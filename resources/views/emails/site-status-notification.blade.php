@component('mail::message')
# Site Status Update

Dear {{ $site->publisher->name ?? 'Publisher' }},

@switch($action)
    @case('update')
        Your site **{{ $site->site_name }}** has been updated by an administrator.
        
        @if($oldData)
        **Changes made:**
        
        @if($oldData['site_name'] != $site->site_name)
        - **Site Name:** {{ $oldData['site_name'] }} → {{ $site->site_name }}
        @endif
        
        @if($oldData['site_url'] != $site->site_url)
        - **Site URL:** {{ $oldData['site_url'] }} → {{ $site->site_url }}
        @endif
        
        @if(($oldData['da'] ?? null) != $site->da)
        - **DA:** {{ $oldData['da'] ?? 'N/A' }} → {{ $site->da }}
        @endif
        
        @if(($oldData['dr'] ?? null) != $site->dr)
        - **DR:** {{ $oldData['dr'] ?? 'N/A' }} → {{ $site->dr }}
        @endif
        
        @if(($oldData['traffic'] ?? null) != $site->traffic)
        - **Traffic:** {{ number_format($oldData['traffic'] ?? 0) }} → {{ number_format($site->traffic) }}
        @endif
        @endif
        @break
    
    @case('activated')
        Your site **{{ $site->site_name }}** has been **activated** and is now live on our platform.
        
        **Next steps:**
        - Your site will appear in our catalog
        - Advertisers can now view and purchase placements
        - You will receive notifications when orders are placed
        @break
    
    @case('deactivated')
        Your site **{{ $site->site_name }}** has been **deactivated**.
        
        **What this means:**
        - Your site is no longer visible in our catalog
        - New orders cannot be placed
        - Existing orders will be fulfilled as agreed
        
        Please contact support if you believe this is an error.
        @break
    
    @case('verified')
        Congratulations! Your site **{{ $site->site_name }}** has been **verified**.
        
        **Benefits of verification:**
        - Higher trust from advertisers
        - Priority placement in search results
        - Verified badge displayed on your site listing
        @break
    
    @case('unverified')
        Your site **{{ $site->site_name }}** has been **unverified**.
        
        Please review your site information and ensure it meets our quality guidelines.
        
        Contact support for more information about this decision.
        @break
    
    @default
        There has been a status change for your site **{{ $site->site_name }}**.
@endswitch

### Current Site Details

- **Site Name:** {{ $site->site_name }}
- **Site URL:** {{ $site->site_url }}
- **Category:** {{ $site->category }}
- **Price:** €{{ number_format($site->price, 2) }}
- **DA/DR:** {{ $site->da }}/{{ $site->dr }}
- **Traffic:** {{ number_format($site->traffic) }} monthly visitors

@component('mail::button', ['url' => url('/login')])
View Your Sites
@endcomponent

If you have any questions, please don't hesitate to contact our support team.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent