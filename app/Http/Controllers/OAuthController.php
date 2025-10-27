<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class OAuthController extends Controller
{
    public function googleAuth()
    {
        return response()->json([
            'oauth_service' => 'google',
            'oauth_id' => $oauth_id,
        ]);
    }

    public function appleAuth()
    {
        return response()->json([
            'oauth_service' => 'google',
            'oauth_id' => $oauth_id,
        ]);
    }
}
