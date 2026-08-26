/**
 * Browser-side preparation for the courier registration documents.
 *
 * A courier sends five documents in one multipart request.  Mobile camera
 * images routinely exceed common shared-hosting request limits, so merely
 * validating the files on Laravel would be too late: the web server rejects
 * the entire request with HTTP 413 before PHP receives it.  We keep the
 * payload deliberately small, while Laravel still validates every file.
 */

const IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp'])
const PDF_TYPE = 'application/pdf'
const IMAGE_EXTENSIONS = /\.(?:jpe?g|png|webp)$/i
const PDF_EXTENSION = /\.pdf$/i
const MAX_SOURCE_IMAGE_BYTES = 20 * 1024 * 1024
const MAX_IMAGE_EDGE = 1600

export class CourierDocumentError extends Error {
    constructor(code) {
        super(code)
        this.code = code
    }
}

export function isCourierImage(file) {
    return IMAGE_TYPES.has(file?.type) || IMAGE_EXTENSIONS.test(file?.name || '')
}

export function isCourierPdf(file) {
    return file?.type === PDF_TYPE || PDF_EXTENSION.test(file?.name || '')
}

export function bytesToMegabytes(bytes) {
    return (bytes / (1024 * 1024)).toFixed(bytes >= 10 * 1024 * 1024 ? 0 : 1)
}

/**
 * Resize and encode an image as JPEG.  PDF files are intentionally not
 * modified because client-side PDF re-encoding risks corrupting identity
 * documents; they are simply constrained to the same safe file limit.
 */
export async function prepareCourierDocument(file, { maxFileBytes, targetImageBytes }) {
    if (!file) return null

    if (isCourierPdf(file)) {
        if (file.size > maxFileBytes) throw new CourierDocumentError('pdf_too_large')

        return { file, optimized: false }
    }

    if (!isCourierImage(file)) throw new CourierDocumentError('unsupported_type')
    if (file.size > MAX_SOURCE_IMAGE_BYTES) throw new CourierDocumentError('source_too_large')

    let source
    try {
        source = await loadImage(file)
        let width = source.width
        let height = source.height
        const largestEdge = Math.max(width, height)

        if (largestEdge > MAX_IMAGE_EDGE) {
            const scale = MAX_IMAGE_EDGE / largestEdge
            width = Math.max(1, Math.round(width * scale))
            height = Math.max(1, Math.round(height * scale))
        }

        let best = null

        // Try quality reductions first, then progressively reduce dimensions
        // for high-resolution camera photos.  The final File is always below
        // the configured server-side per-document threshold or is rejected
        // before any multipart request is made.
        for (let scale = 1; scale >= 0.49; scale *= 0.8) {
            const scaledWidth = Math.max(1, Math.round(width * scale))
            const scaledHeight = Math.max(1, Math.round(height * scale))
            const canvas = drawToCanvas(source, scaledWidth, scaledHeight)

            for (const quality of [0.84, 0.76, 0.68, 0.6]) {
                const blob = await canvasToBlob(canvas, quality)
                if (!best || blob.size < best.size) best = blob
                if (blob.size <= targetImageBytes) {
                    return { file: toJpegFile(file, blob), optimized: true }
                }
            }
        }

        if (!best || best.size > maxFileBytes) throw new CourierDocumentError('cannot_compress')

        return { file: toJpegFile(file, best), optimized: true }
    } catch (error) {
        if (error instanceof CourierDocumentError) throw error
        throw new CourierDocumentError('cannot_process')
    } finally {
        // ImageBitmap owns native image memory on modern browsers. Releasing
        // it matters when a courier replaces several high-resolution photos.
        source?.close?.()
    }
}

async function loadImage(file) {
    if ('createImageBitmap' in window) {
        try {
            return await window.createImageBitmap(file, { imageOrientation: 'from-image' })
        } catch {
            // Older Android WebViews can expose createImageBitmap but reject
            // the imageOrientation option.  The Image fallback still lets the
            // user complete registration instead of failing silently.
        }
    }

    return await new Promise((resolve, reject) => {
        const image = new Image()
        const objectUrl = URL.createObjectURL(file)
        image.onload = () => {
            URL.revokeObjectURL(objectUrl)
            resolve(image)
        }
        image.onerror = () => {
            URL.revokeObjectURL(objectUrl)
            reject(new Error('Image could not be decoded'))
        }
        image.src = objectUrl
    })
}

function drawToCanvas(image, width, height) {
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const context = canvas.getContext('2d', { alpha: false })
    context.fillStyle = '#ffffff'
    context.fillRect(0, 0, width, height)
    context.drawImage(image, 0, 0, width, height)

    return canvas
}

function canvasToBlob(canvas, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) resolve(blob)
            else reject(new Error('Image could not be encoded'))
        }, 'image/jpeg', quality)
    })
}

function toJpegFile(original, blob) {
    const name = original.name.replace(/\.[^.]+$/, '') || 'document'

    return new File([blob], `${name}.jpg`, {
        type: 'image/jpeg',
        lastModified: original.lastModified,
    })
}
