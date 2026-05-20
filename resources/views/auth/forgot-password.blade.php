@extends('layouts.app')

@section('title', 'Forgot Password')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 py-5">
            <div class="card p-4 shadow rounded-3">

                <h3 class="text-center mb-3">Forgot Password</h3>

                <form id="forgotForm">
                    @csrf
                    <input type="email" name="email" class="form-control mb-3" placeholder="Enter your email" required>
                    
                    {{-- reCAPTCHA --}}
                    <!-- <div class="mb-3">
                        <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                    </div> -->

                    <button type="submit" class="btn btn-primary w-100" id="sendBtn">Send Reset Link</button>
                </form>

                <div class="text-center mt-3">
                    <a href="{{ route('login') }}">Back to Login</a>
                </div>

            </div>
        </div>
    </div>
</div>

{{-- Toast Container --}}
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

{{-- reCAPTCHA --}}
<script src="https://www.google.com/recaptcha/api.js" async defer></script>

<script>
document.getElementById('forgotForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const sendBtn = document.getElementById('sendBtn');
    if(sendBtn.disabled) return;
    sendBtn.disabled = true;
    sendBtn.innerText = 'Sending...';

    const formData = new FormData(this);
    let data;

    try {
        const res = await fetch("{{ route('password.email') }}", {
            method:'POST',
            headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}' },
            body: formData
        });
        data = await res.json();
    } catch(e){
        alert('Server error occurred.');
        sendBtn.disabled = false;
        sendBtn.innerText = 'Send Reset Link';
        return;
    }

    const toastContainer = document.getElementById('toastContainer');
    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-white border-0';
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${data.message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;
    toastEl.classList.add(data.status==='success'?'bg-success':'bg-danger');
    toastContainer.appendChild(toastEl);
    new bootstrap.Toast(toastEl,{delay:5000}).show();

    sendBtn.disabled = false;
    sendBtn.innerText = 'Send Reset Link';
    grecaptcha.reset();
});
</script>
@endsection