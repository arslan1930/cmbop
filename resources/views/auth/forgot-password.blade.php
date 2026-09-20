@extends('layouts.app')

@section('title', 'Forgot Password | SEOLinkBuildings Guest Post Marketplace')

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 py-5">
            <div class="card p-4 shadow rounded-3">

                <h1 class="h3 text-center mb-3">Forgot Password</h1>

                <form id="forgotForm" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="forgotEmail">Email</label>
                        <input type="email" name="email" id="forgotEmail" class="form-control" placeholder="Enter your email" autocomplete="email" required aria-describedby="emailError">
                        <div class="invalid-feedback" id="emailError" role="alert" aria-live="polite"></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="sendBtn">Send Reset Link</button>
                </form>

                <div class="text-center mt-3">
                    <a href="{{ route('login', absolute: false) }}">Back to Login</a>
                </div>

            </div>
        </div>
    </div>
</div>

{{-- Toast Container --}}
<div class="slb-toast-stack" id="toastContainer"></div>

<script>
document.getElementById('forgotForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const sendBtn = document.getElementById('sendBtn');
    if(sendBtn.disabled) return;
    sendBtn.disabled = true;
    sendBtn.innerText = 'Sending...';

    const emailInput = document.getElementById('forgotEmail');
    const emailError = document.getElementById('emailError');
    if (emailInput) {
        emailInput.classList.remove('is-invalid');
        emailInput.removeAttribute('aria-invalid');
    }
    if (emailError) {
        emailError.textContent = '';
        emailError.classList.remove('d-block');
    }

    const formData = new FormData(this);
    let res;
    let data;

    try {
        res = await fetch("{{ route('password.email', absolute: false) }}", {
            method:'POST',
            credentials: 'same-origin',
            headers:{
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: formData
        });
        data = await res.json();
    } catch(err){
        showForgotMessage(
            (typeof slbHttpMessage === 'function')
                ? slbHttpMessage({ status: res && res.status ? res.status : 0 }, 'Please try again in a moment.')
                : 'Please try again in a moment.',
            false
        );
        sendBtn.disabled = false;
        sendBtn.innerText = 'Send Reset Link';
        return;
    }

    if (data.status === 'validation') {
        const message = (data.errors && data.errors.email && data.errors.email[0])
            || data.message
            || 'Please enter a valid email.';
        if (emailInput) {
            emailInput.classList.add('is-invalid');
            emailInput.setAttribute('aria-invalid', 'true');
        }
        if (emailError) {
            emailError.textContent = message;
            emailError.classList.add('d-block');
        }
        showForgotMessage(message, false);
    } else {
        const ok = data.status === 'success';
        const message = ok
            ? (data.message || 'If an account with this email exists, a password reset link has been sent.')
            : ((typeof slbHttpMessage === 'function')
                ? slbHttpMessage({ status: res.status, data: data }, data.message || 'Something went wrong. Please try again.')
                : (data.message || 'Something went wrong. Please try again.'));
        showForgotMessage(message, ok);
    }

    sendBtn.disabled = false;
    sendBtn.innerText = 'Send Reset Link';
});

function showForgotMessage(message, success) {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        if (typeof slbAlert === 'function') {
            slbAlert({ icon: success ? 'success' : 'error', title: success ? 'Email sent' : 'Something went wrong', text: message });
        }
        return;
    }
    const toastEl = document.createElement('div');
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.className = 'toast align-items-center text-white border-0 ' + (success ? 'bg-success' : 'bg-danger');
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
    toastContainer.appendChild(toastEl);
    new bootstrap.Toast(toastEl,{delay:5000}).show();
}
</script>
@endsection
