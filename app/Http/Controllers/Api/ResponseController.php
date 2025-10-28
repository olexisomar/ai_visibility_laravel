<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResponseController extends Controller
{
    /**
     * Get single response with links
     */
    public function show($id)
    {
        $response = DB::table('responses')
            ->where('id', $id)
            ->first();
        
        if (!$response) {
            return response()->json(['error' => 'Response not found'], 404);
        }
        
        // Get links for this response
        $links = DB::table('response_links')
            ->where('response_id', $id)
            ->get(['url', 'anchor', 'source'])
            ->toArray();
        
        return response()->json([
            'response' => $response,
            'links' => $links,
        ]);
    }
}