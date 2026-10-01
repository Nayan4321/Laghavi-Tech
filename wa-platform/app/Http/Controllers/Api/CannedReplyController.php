<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CannedReply;
use Illuminate\Http\Request;

class CannedReplyController extends Controller
{
    public function index(Request $request)
    {
        $ids = $request->user()->visibleNumberIds();

        return CannedReply::query()
            ->where(function ($q) use ($ids) {
                $q->whereNull('whatsapp_number_id')
                    ->orWhereIn('whatsapp_number_id', $ids);
            })
            ->get(['id', 'shortcut', 'title', 'body']);
    }
}
