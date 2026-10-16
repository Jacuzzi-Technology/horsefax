<?php

namespace App\Http\Controllers;

use App\Support\PedigreeLookup;
use Illuminate\Http\Request;

/** JSON endpoints behind the pedigree form's autofill. */
class PedigreeController extends Controller
{
    public function lookup(Request $request)
    {
        $data = $request->validate(['q' => 'required|string|max:120', 'pos' => 'required|regex:/^[sd]{1,3}$/']);

        return response()->json(['results' => PedigreeLookup::search($data['q'], $request->user(), $data['pos'])]);
    }

    public function ancestry(Request $request)
    {
        $data = $request->validate(['kind' => 'required|in:record,entry', 'id' => 'required|uuid']);

        return response()->json(['ancestors' => PedigreeLookup::ancestry($data['kind'], $data['id'], $request->user())]);
    }
}
