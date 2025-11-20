<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\TransactionMessage;
use App\Http\Requests\TransactionMessageRequest;

class TransactionController extends Controller
{
//取引チャット画面表示
    public function home(Item $item_id)
    {
        $authId = auth()->id();

        // アイテム詳細情報リレーションロード
        $item_id->load([
            'images',
            'categories',
            'condition',
            'user.profile',
            'buyer.profile',
            'transactionMessages.user.profile'
        ]);

        // アクセス日時更新
        if ($item_id->user->id === $authId) {
            $item_id->last_seller_access = now();
            $item_id->save();
        } elseif ($item_id->buyer && $item_id->buyer->id === $authId) {
            $item_id->last_buyer_access = now();
            $item_id->save();
        }

        // チャットの取引メッセージを古い順に取得
        $transactionMessages = $item_id->transactionMessages->sortBy('sent_at');

        // 相手ユーザー判定
        if ($item_id->buyer && $item_id->buyer->id !== $authId) {
            $otherUser = $item_id->buyer;
        } else {
            $otherUser = $item_id->user->id !== $authId ? $item_id->user : null;
        }

        // trading状態かつ自身が出品者or購入者のアイテム取得
        $items = Item::where(function ($query) use ($authId) {
                $query->where('user_id', $authId)
                    ->orWhere('buyer_id', $authId);
            })
            ->whereIn('status', ['trading', 'completed']) 
            ->with(['transactionMessages' => function($q) {
                $q->orderBy('sent_at', 'desc');
            }])
            ->get()
            ->sortByDesc(function($item) {
                return optional($item->transactionMessages->first())->sent_at;
            });

        // 未読メッセージ数を計算してセット
        foreach ($items as $item) {
            $lastAccess = null;

            if ($item->buyer_id === $authId) {
                $lastAccess = $item->last_buyer_access;
            } elseif ($item->user_id === $authId) {
                $lastAccess = $item->last_seller_access;
            }

            $item->unread_count = $item->transactionMessages
                ->filter(function ($message) use ($lastAccess, $authId) {
                    return $message->sent_at > $lastAccess && $message->user_id !== $authId;
                })
                ->count();
        }

        return view('users.trade_message', [
            'detailItem' => $item_id,
            'transactionMessages' => $transactionMessages,
            'otherUser' => $otherUser,
            'items' => $items, // 未読数も含まれた状態でビューへ
        ]);
    }


    public function store(TransactionMessageRequest $request)
    {
        $request->validate([
            'message' => 'nullable|string',
            'image' => 'nullable|image|max:2048', // 最大2MBの画像ファイル
        ]);

        // 文字と画像の両方が空ならエラーにする（必要に応じて）
        if (empty($request->message) && !$request->hasFile('image')) {
            return redirect()->back()->withErrors(['message' => 'メッセージまたは画像を入力してください。']);
        }

        $transactionMessage = new TransactionMessage();
        $transactionMessage->item_id = $request->item_id;
        $transactionMessage->user_id = auth()->id();
        $transactionMessage->message = $request->message ?? '';

        // 画像があれば保存してパスをセット
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('transaction_images', 'public');
            $transactionMessage->image_path = $path;
        }

        $transactionMessage->sent_at = now();
        $transactionMessage->save();

        return redirect()->back();
    }


// 取引メッセージ更新
    public function update(Item $item, TransactionMessage $message, Request $request)
    {
        // バリデーション
        $request->validate([
            'message' => 'required|string|max:400',
        ]);



        // メッセージ更新
        $message->message = $request->input('message');
        $message->save();

        return redirect()->back();
    }

    // 取引メッセージ削除
    public function destroy(Item $item, TransactionMessage $message)
    {
        

        // メッセージ削除
        $message->delete();

        return redirect()->back();
    }

}