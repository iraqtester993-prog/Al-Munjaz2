<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminPreferencesController extends Controller
{
    public function theme(Request $request)
    {
        $data = $request->validate(['theme' => ['required', 'in:light,dark']]);

        $request->user()->update(['theme' => $data['theme']]);

        // Dashboard and branch portal theme changes are saved in the
        // background, so do not redirect them back through Inertia.
        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back();
    }

    public function locale(Request $request)
    {
        $data = $request->validate(['locale' => ['required', 'in:ar,en,ku']]);

        $request->user()->update(['locale' => $data['locale']]);
        $request->session()->put('locale', $data['locale']);
        $request->session()->flash('inertia.translations.refresh', true);

        return back();
    }
}
