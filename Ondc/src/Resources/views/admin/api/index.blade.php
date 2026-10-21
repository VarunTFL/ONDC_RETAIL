<x-admin::layouts>
    <x-slot:title>
        API Manager
    </x-slot>

    <div class="content">

        {{-- Success Message --}}
        @if(session('success'))
            <div style="color: green;">
                {{ session('success') }}
            </div>
        @endif

        {{-- Error Message --}}
        @if(session('error'))
            <div style="color: red;">
                {{ session('error') }}
            </div>
        @endif

        {{-- SEND API --}}
        <h2>Send API</h2>

        <form method="POST" action="{{ route('ondc.api.send') }}">
            @csrf

            <div style="margin-bottom: 10px;">
                <label>Enter JSON Payload:</label><br>
                <textarea name="data" rows="10" cols="80" placeholder='{"key":"value"}'>{{ old('data') }}</textarea>
            </div>

            @error('data')
                <div style="color:red;">{{ $message }}</div>
            @enderror

            <button type="submit">Send API</button>
        </form>

        <hr style="margin: 30px 0;">

        {{-- FETCH RESPONSE --}}
        <h2>Fetch ONDC Response</h2>

        <form method="GET" action="{{ route('ondc.api.fetch') }}">
            <button type="submit">Fetch Response</button>
        </form>


        <h2>Send ONDC Search Payload</h2>

        <form method="POST" action="{{ url('api/search') }}">
            @csrf

            <textarea name="payload" rows="15" cols="100">
        {
        "context": {
            "domain": "ONDC:RET10",
            "action": "search",
            "country": "IND",
            "core_version": "1.2.0",
            "bap_id": "buyer-app.com",
            "bap_uri": "https://buyer-app.com",
            "bpp_id": "seller-app.com",
            "bpp_uri": "https://seller-app.com",
            "transaction_id": "txn123",
            "message_id": "msg123",
            "timestamp": "2024-01-01T10:00:00Z",
            "city": "std:080"
        },
        "message": {
            "intent": {
            "item": {
                "descriptor": {
                "name": "laptop"
                }
            }
            }
        }
        }
            </textarea>

            <br><br>
            <button type="submit">Send on_search</button>
        </form>


    </div>
</x-admin::layouts>
