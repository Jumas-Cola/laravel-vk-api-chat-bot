<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use VK\Client\VKApiClient;

class NewMessageController extends Controller
{
    public function __construct()
    {
        $this->access_token = env("VK_SECRET_KEY");
        $this->vk = new VKApiClient();
        $this->buttons = json_encode([
            "one_time"=>false,
            "buttons"=>[
                [
                    [
                        "action"=>[
                            "type"=>"text",
                            "payload"=>"{\"button\":\"!н\"}",
                            "label"=>"!н"
                        ],
                        "color"=>"positive"
                    ],

                    [
                        "action"=>[
                            "type"=>"text",
                            "payload"=>"{\"button\":\"!с\"}",
                            "label"=>"!с"
                        ],
                        "color"=>"negative"
                    ]
                ]
            ]
        ]);
    }

    public function handle(Request $request)
    {
        $object = $request->object;
        $message = $object['message'];
        $text = mb_strtolower($message['text']);
        $id = $message['from_id'];
        $user = User::firstOrNew(['id' => $id]);

        /* Обработка комнд */
        $handler = match (true) {
            str_contains($text, '!н') => 'new_chat', 
            str_contains($text, '!с') => 'stop_chat', 
            $user->state == 'in_dialog' => 'new_message',
            default => 'hello',
        };

        return $this->$handler($request);
    }

    /* 
    * Приветственное сообщение 
    */
    public function hello(Request $request)
    {
        $object = $request->object;
        $message = $object['message'];
        $id = $message['from_id'];
        $peer_id = $message['peer_id'];


        /* Формирование ответа */
        $response = [
            'peer_id' => $peer_id,
            'message' => "Привет!\nНовый диалог - !н\nОстановить диалог - !с",
            'keyboard' => $this->buttons,
            'random_id' => rand(),
        ];


        /*
        * Запись пользователя в базу
        * и обновление его состояния 
        */
        $user = User::firstOrNew(['id' => $id]);
        $user->state = 'init';
        $user->save();


        /* Отправка ответного сообщения */
        $this->vk->messages()->send($this->access_token, $response);

        return 'ok';
    }

    public function new_chat(Request $request)
    {
        $object = $request->object;
        $message = $object['message'];
        $id = $message['from_id'];
        $peer_id = $message['peer_id'];

        $user = User::firstOrNew(['id' => $id]);

        /* 
        * Если пользователь уже в диалоге,
        * оповестить собеседника о его завершении
        */ 
        if ( $user->state == 'in_dialog' ) {
            $current_other = $user->other;
            $current_other->state = 'init';
            $current_other->save();

            $response = [
                'peer_id' => $current_other->id,
                'message' => "Собеседник завершил диалог.\nНовый диалог - !н\nОстановить -диалог !с",
                'keyboard' => $this->buttons,
                'random_id' => rand(),
            ];

            $this->vk->messages()->send($this->access_token, $response);
        }

        $other = User::where('state', 'in_search')
            ->orderBy('updated_at', 'asc')->first();

        if ( !empty($other) ) {
            $user->state = 'in_dialog';
            $user->other_id = $other->id;
            $user->save();
            $other->state = 'in_dialog';
            $other->other_id = $user->id;
            $other->save();

            $response = [
                'peer_id' => $peer_id,
                'message' => 'Собеседник найден, общайтесь!',
                'keyboard' => $this->buttons,
                'random_id' => rand(),
            ];

            $this->vk->messages()->send($this->access_token, $response);

            $response['peer_id'] = $other->id;
            $this->vk->messages()->send($this->access_token, $response);

            return 'ok';
        }

        $user->state = 'in_search';
        $user->save();

        $response = [
            'peer_id' => $peer_id,
            'message' => 'Вы добавлены в очередь поиска!',
            'keyboard' => $this->buttons,
            'random_id' => rand(),
        ];

        $this->vk->messages()->send($this->access_token, $response);

        return 'ok';
    }

    public function stop_chat(Request $request)
    {
        $object = $request->object;
        $message = $object['message'];
        $id = $message['from_id'];
        $peer_id = $message['peer_id'];

        $user = User::firstOrNew(['id' => $id]);

        /* 
        * Если пользователь уже в диалоге,
        * оповестить собеседника о его завершении
        */ 
        if ( $user->state == 'in_dialog' ) {
            $current_other = $user->other;
            $current_other->state = 'init';
            $current_other->save();

            $response = [
                'peer_id' => $current_other->id,
                'message' => "Собеседник завершил диалог.\nНовый диалог - !н\nОстановить -диалог !с",
                'keyboard' => $this->buttons,
                'random_id' => rand(),
            ];

            $this->vk->messages()->send($this->access_token, $response);
        }

        $message = match ( $user->state ) {
            'in_dialog' => 'Вы завершили диалог!',
            'in_search' => 'Поиск остановлен',
            default => "Новый диалог - !н\nОстановить -диалог !с",
        };

        $response = [
            'peer_id' => $peer_id,
            'message' => $message,
            'keyboard' => $this->buttons,
            'random_id' => rand(),
        ];

        $user->state = 'init';
        $user->save();

        $this->vk->messages()->send($this->access_token, $response);

        return 'ok';
    }

    public function new_message(Request $request)
    {
        $object = $request->object;
        $message = $object['message'];
        $id = $message['from_id'];
        $peer_id = $message['peer_id'];

        $user = User::findOrFail($id);

        $attachments = '';

        foreach ( $message['attachments'] as $a ) {
            $attachments .= "{$a['type']}{$a[$a['type']]['owner_id']}_{$a[$a['type']]['id']}_{$a[$a['type']]['access_key']},";
        }

        $response = [
            'peer_id' => $user->other_id,
            'message' => $message['text'],
            'attachment' => $attachments,
            'keyboard' => $this->buttons,
            'random_id' => rand(),
        ];

        $this->vk->messages()->send($this->access_token, $response);

        return 'ok';
    }
}
