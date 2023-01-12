<?php

namespace App\Http\Controllers\API;

use Exception;
use Midtrans\Snap;
use Midtrans\Config;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Helpers\ResponseFormatter;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;


class TransactionController extends Controller
{

    public function all(Request $request)
    {
        $id      = $request->input('id');
        $limit   = $request->input('limit', 6);
        $food_id = $request->input('food_id');
        $status  = $request->input('status');

        if($id)
        {
            $transaction = Transaction::with(['food', 'user'])->find($id);

            if($transaction)
            {
                return ResponseFormatter::success(
                    $transaction,
                    'Data Transaction berhasil di ambil'
                );
            } else {
                return ResponseFormatter::error(
                    null,
                    'Data transaksi tidak ada',
                    404
                );
            }
        }

        // ngambil data user yang sedang login saja
        $transaction = Transaction::with(['food', 'user'])->where('user_id', Auth::user()->id);

        if($food_id)
        {
            $transaction->where('food_id', $food_id);
        }

        if($status)
        {
            $transaction->where('status', $status);
        }

        return ResponseFormatter::success(
            $transaction->paginate($limit),
            'Data list transaksi berhasil di ambil'
        );

    }

    public function update(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        $transaction->update($request->all());

        return ResponseFormatter::success($transaction, 'Transaksi berhadil di update');
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'food_id'  => 'required|exists:food,id',
            'user_id'  => 'required|exists:users,id',
            'quantity' => 'required',
            'total'    => 'required',
            'status'   => 'required'
        ]);

        $transaction = Transaction::create([
            'food_id'     => $request->food_id,
            'user_id'     => $request->user_id,
            'quantity'    => $request->quantity,
            'total'       => $request->total,
            'status'      => $request->status,
            'payment_url' => ''
        ]);

        // konfigurasi midtrans
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$is3ds = config('services.midtrans.is3ds');

        // panggil transaksi yang sudah di buat di atas
        $transaction = Transaction::with(['food', 'user'])->find($transaction->id);

        // membuat transaksi midtrans
        $midtrans = [
            'transaction_details' => [
                'order_id' => $transaction->id,
                'gross_amount' => (int) $transaction->total, //convert ke integer
            ],
            'customer_details' => [
                'first_name' => $transaction->user->name,
                'email' => $transaction->user->email,
            ],
            'enabled_payments' => ['gopay', 'bank_transfer'],
            'vtweb' => [],
        ];

        // memanggil midtrans
        try {
            // Ambil halaman payment midtrans
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;

            $transaction->payment_url = $paymentUrl;
            $transaction->save();

            // mengembalikan data ke api
            return ResponseFormatter::success($transaction, 'Transaksi berhasil');

        } catch (Exception $error) {
            return ResponseFormatter::error($error->getMessage(), 'Transaksi Gagal');
        }

        // mengembalikan data ke api

    }

}
