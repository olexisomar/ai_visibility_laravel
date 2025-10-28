<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use Illuminate\Http\Request;

class PersonaController extends Controller
{
    public function index()
    {
        $personas = Persona::orderBy('updated_at', 'desc')->get();

        return response()->json(['rows' => $personas]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:120',
            'brand_id' => 'nullable|string|exists:brands,id',
            'description' => 'required|string',
            'attributes' => 'nullable',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($validated['id'])) {
            $persona = Persona::findOrFail($validated['id']);
            $persona->update($validated);
        } else {
            $validated['is_active'] = true;
            $persona = Persona::create($validated);
        }

        return response()->json(['ok' => true, 'id' => $persona->id]);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer',
        ]);

        $persona = Persona::findOrFail($validated['id']);
        $persona->delete();

        return response()->json(['ok' => true, 'deleted' => 1]);
    }
}