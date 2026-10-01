<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappNumber;
use Illuminate\Http\Request;

class NumberController extends Controller
{
    public function index(Request $request)
    {
        $ids = $request->user()->visibleNumberIds();

        return WhatsappNumber::whereIn('id', $ids)
            ->where('is_active', true)
            ->get(['id', 'label', 'display_phone']);
    }
}
