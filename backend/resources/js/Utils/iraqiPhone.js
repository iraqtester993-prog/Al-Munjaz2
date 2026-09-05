const arabicDigits = '٠١٢٣٤٥٦٧٨٩'
const persianDigits = '۰۱۲۳۴۵۶۷۸۹'

/**
 * Keeps phone entry predictable on mobile keyboards and when pasting a
 * number: Arabic/Persian numerals become ASCII digits, separators disappear,
 * and the field never grows beyond the required eleven digits.
 */
export function normalizeIraqiMobilePhone(value) {
    return String(value ?? '')
        .replace(/[٠-٩]/g, (digit) => String(arabicDigits.indexOf(digit)))
        .replace(/[۰-۹]/g, (digit) => String(persianDigits.indexOf(digit)))
        .replace(/\D/g, '')
        .slice(0, 11)
}

export function isIraqiMobilePhone(value) {
    return /^(?:077|078)\d{8}$/.test(normalizeIraqiMobilePhone(value))
}
