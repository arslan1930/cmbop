@php
    $refundUrl = function_exists('localized_url')
        ? localized_url('refund-policy')
        : url('/refund-policy');
@endphp
<ul class="buy-confidence" aria-label="What happens after you pay">
    <li>Price shown is what you pay — no surprise checkout fees.</li>
    <li>After pay: the publisher delivers, then you approve the live URL.</li>
    <li>
        Non-delivery or declined work:
        <a href="{{ $refundUrl }}">wallet refund per our refund policy</a>.
    </li>
</ul>

@once
    <style>
        .buy-confidence {
            list-style: none;
            margin: 10px 0 0;
            padding: 0;
            font-size: 12px;
            line-height: 1.45;
            color: #64748b;
        }
        .buy-confidence li {
            position: relative;
            padding-left: 14px;
            margin-bottom: 4px;
        }
        .buy-confidence li:last-child {
            margin-bottom: 0;
        }
        .buy-confidence li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0.55em;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #3faeb2;
        }
        .buy-confidence a {
            color: #185054;
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .buy-confidence a:hover {
            color: #3faeb2;
        }
    </style>
@endonce
