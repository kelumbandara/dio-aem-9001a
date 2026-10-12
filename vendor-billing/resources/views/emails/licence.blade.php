<p>Dear {{ $customer->contact_name ?: $customer->name }},</p>
<p>Thank you for your payment. Your licence now runs until <b>{{ $licence->valid_until->format('j F Y') }}</b>.</p>
<p>If your system is hosted by us it picks this up on its own. Otherwise open <b>Administration → Licence</b> in the system, choose to install a licence and paste the whole text below.</p>
<pre style="white-space:pre-wrap;word-break:break-all">{{ $licence->token }}</pre>
<p>Licence {{ $licence->serial }}</p>
