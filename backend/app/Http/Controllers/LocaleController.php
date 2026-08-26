<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Guest pages have no user record yet, so retain their chosen language in
     * the session.  Once signed in, the account preference remains the source
     * of truth and is changed from the profile/dashboard controls.
     */
    public function update(Request $request)
    {
        $data = $request->validate(['locale' => ['required', 'in:ar,en,ku']]);

        $request->session()->put('locale', $data['locale']);

        return back();
    }
}
