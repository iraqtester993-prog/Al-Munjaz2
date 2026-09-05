<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Document::query()->where('user_id', $request->user()->id)->latest('id')->get()->map(fn (Document $document) => $this->data($document))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:30'], 'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']]);
        $path = $request->file('file')->store("documents/{$request->user()->id}", 'public');
        $document = Document::create(['user_id' => $request->user()->id, 'type' => $data['type'], 'path' => $path, 'status' => 'pending']);

        return response()->json(['data' => $this->data($document)], 201);
    }

    private function data(Document $document): array
    {
        return ['id' => $document->id, 'type' => $document->type, 'status' => $document->status, 'url' => asset('storage/'.$document->path), 'created_at' => $document->created_at?->toISOString()];
    }
}
