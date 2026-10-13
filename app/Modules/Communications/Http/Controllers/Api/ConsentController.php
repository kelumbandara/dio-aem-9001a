<?php

namespace App\Modules\Communications\Http\Controllers\Api;

use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Http\Controllers\Concerns\PresentsMessages;
use App\Modules\Communications\Services\Address;
use App\Modules\Communications\Services\ConsentService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsentController extends Controller
{
    use PresentsMessages;

    /** The status and history of one address on one channel. */
    public function index(Request $request, ConsentService $consents): JsonResponse
    {
        $this->needs($request, 'comm.message.view');
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp'])], 'address' => ['required', 'string', 'max:190']]);
        $address = Address::normalize($data['channel'], $data['address']) ?? throw CommunicationsException::make('comm.address_invalid', 'That is not a valid address.', 422, 'address');

        return response()->json(['data' => [
            'channel' => $data['channel'], 'address' => Address::mask($data['channel'], $address), 'status' => $consents->status($data['channel'], $address),
            'history' => array_map(fn ($c) => ['status' => $c->status, 'source' => $c->source, 'note' => $c->note, 'at' => $c->created_at->toIso8601String()], $consents->history($data['channel'], $address)),
        ]]);
    }

    /** Records that an address allowed or refused contact (for example "the customer asked by phone to stop"). */
    public function store(Request $request, ConsentService $consents): JsonResponse
    {
        $this->needs($request, 'comm.consent.manage');
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp'])], 'address' => ['required', 'string', 'max:190'], 'status' => ['required', Rule::in(['opted_in', 'opted_out'])], 'note' => ['required', 'string', 'max:255']]);
        $address = Address::normalize($data['channel'], $data['address']) ?? throw CommunicationsException::make('comm.address_invalid', 'That is not a valid address.', 422, 'address');
        $c = $consents->record($data['channel'], $address, $data['status'], 'manual', $data['note'], $request->user()->id);

        return response()->json(['data' => ['channel' => $c->channel, 'address' => Address::mask($c->channel, $c->address), 'status' => $c->status]], 201);
    }
}
