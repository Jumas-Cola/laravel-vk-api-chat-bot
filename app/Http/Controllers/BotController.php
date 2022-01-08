<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BotController extends Controller
{
    public function handle(Request $request)
    {
        switch ( $request->type ) {
            case 'confirmation':
                if ( $request->group_id == env('VK_GROUP_ID') ) {
                    return env('VK_CONFIRMATION');
                }

                return response()->json(['message' => 'Group id invalid'], 403);
            case 'message_new':
                return (new NewMessageController)->handle($request);
            default:
                return response()->json(['message' => 'ok']);
        }
    }
}
