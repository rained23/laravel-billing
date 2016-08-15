<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice - {{$invoice-id}}</title>

    <style>
    .invoice-box{
        max-width:800px;
        margin:auto;
        padding:30px;
        border:1px solid #eee;
        box-shadow:0 0 10px rgba(0, 0, 0, .15);
        font-size:16px;
        line-height:24px;
        font-family:'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
        color:#555;
    }

    .invoice-box table{
        width:100%;
        line-height:inherit;
        text-align:left;
    }

    .invoice-box table td{
        padding:5px;
        vertical-align:top;
    }

    .invoice-box table tr td:nth-child(2){
        text-align:right;
    }

    .invoice-box table tr.top table td{
        padding-bottom:20px;
    }

    .invoice-box table tr.top table td.title{
        font-size:45px;
        line-height:45px;
        color:#333;
    }

    .invoice-box table tr.information table td{
        padding-bottom:40px;
    }

    .invoice-box table tr.heading td{
        background:#eee;
        border-bottom:1px solid #ddd;
        font-weight:bold;
    }

    .invoice-box table tr.details td{
        padding-bottom:20px;
    }

    .invoice-box table tr.item td{
        border-bottom:1px solid #eee;
    }

    .invoice-box table tr.item.last td{
        border-bottom:none;
    }

    .invoice-box table tr.total td:nth-child(2){
        border-top:2px solid #eee;
        font-weight:bold;
    }

    @media only screen and (max-width: 600px) {
        .invoice-box table tr.top table td{
            width:100%;
            display:block;
            text-align:center;
        }

        .invoice-box table tr.information table td{
            width:100%;
            display:block;
            text-align:center;
        }
    }
    </style>
</head>

<body>
    <div class="invoice-box">
        <table cellpadding="0" cellspacing="0">
            <tr class="top">
                <td colspan="2">
                    <table>
                        <tr>
                            <td class="title">
                                <img src="{{$logoUrl}}" style="width:100%; max-width:300px;">
                            </td>

                            <td>
                                Invoice #: {{$invoice->id}}<br>
                                Created:  {{ date('M jS, Y', strtotime($invoice->date)) }}<br>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            {{-- <tr class="information">
                <td colspan="2">
                    <table>
                        <tr>
                            <td>
                                Next Step Webs, Inc.<br>
                                12345 Sunny Road<br>
                                Sunnyville, TX 12345
                            </td>

                            <td>
                                Acme Corp.<br>
                                John Doe<br>
                                john@example.com
                            </td>
                        </tr>
                    </table>
                </td>
            </tr> --}}

            {{-- <tr class="heading">
                <td>
                    Payment Method
                </td>

                <td>
                    Check #
                </td>
            </tr>

            <tr class="details">
                <td>
                    Check
                </td>

                <td>
                    1000
                </td>
            </tr> --}}

            <tr class="heading">
                <td>
                    Item
                </td>
								<td>
									Date
								</td>
                <td>
                    Price
                </td>
            </tr>

						@foreach ($invoice->items() as $item)
							<tr class="item">
								@if ($item->subscription_id)
									<td>
										@if ($item->description)
											{{ $item->description }}
										@else
											Subscription

											@if ($item->subscription())
												to {{ ucwords(str_replace(array('_', '-'), ' ', $item->subscription()->plan)) }}
											@endif

											@if ($item->quantity > 1)
												(x{{ $item->quantity }})
											@endif
										@endif
									</td>
									<td>
										@if ($item->period_start && $item->period_end)
											{{ date('M jS, Y', strtotime($item->period_start)) }}
											-
											{{ date('M jS, Y', strtotime($item->period_end)) }}
										@endif
									</td>
								@else
									<td>{{ $item->description }}</td>
									<td>&nbsp;</td>
								@endif

								@if ($item->amount >= 0)
									<td>{{ number_format($item->amount / 100, 2) }}</td>
								@else
									<td>-{{ number_format(abs($item->amount) / 100, 2) }}</td>
								@endif
							</tr>
						@endforeach

						@if ($invoice->subtotal)
							<tr class="total">
								<td>&nbsp;</td>
								<td style="text-align: right;">Subtotal:</td>
								<td><strong>{{ number_format($invoice->subtotal / 100, 2) }}</strong></td>
							</tr>
						@endif

						<!-- Display Any Discounts -->
						@if ($invoice->discounts)
							@foreach ($invoice->discounts as $discount)
								<tr class="item">
									<td>
										{{ array_get($discount, 'coupon') }}

										@if (array_get($discount, 'amount_off'))
											({{ array_get($discount, 'amount_off') / 100 }} Off)
										@else
											({{ array_get($discount, 'percent_off') }}% Off)
										@endif
									</td>
									<td>&nbsp;</td>
									<td>
										<strong>
											@if (array_get($discount, 'amount_off'))
												-{{ number_format(abs(array_get($discount, 'amount_off') / 100), 2) }}
											@else
												-{{ number_format($invoice->subtotal * (array_get($discount, 'percent_off') / 100) / 100, 2) }}
											@endif
										</strong>
									</td>
								</tr>
							@endforeach
						@endif

						<!-- Display The Total -->
						@if ($invoice->total && $invoice->discounts)
							<tr class="total">
								<td>&nbsp;</td>
								<td style="text-align: right;">Total:</td>
								<td><strong>{{ number_format($invoice->total / 100, 2) }}</strong></td>
							</tr>
						@endif

						<!-- Display Any Starting Balance -->
						@if ($invoice->starting_balance)
							<tr>
								<td>&nbsp;</td>
								<td style="text-align: right;">Starting Customer Balance:</td>
								<td>
									@if ($invoice->starting_balance >= 0)
										<strong>{{ number_format($invoice->starting_balance / 100, 2) }}</strong>
									@else
										<strong>-{{ number_format(abs($invoice->starting_balance) / 100, 2) }}</strong>
									@endif
								</td>
							</tr>
						@endif

						<!-- Display The Final Amount -->
						<tr class="total">
							<td>&nbsp;</td>
							<td style="text-align: right;"><strong>Amount Paid:</strong.</td>
							<td><strong>{{ number_format($invoice->amount / 100, 2) }}</strong></td>
						</tr>
        </table>
    </div>
</body>
</html>
