@extends('layouts.app')

@section('title', 'Reset Password | SEOLinkBuildings Guest Post Marketplace')

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 py-5">
            <div class="card p-4 shadow rounded-3">

                <h1 class="h3 text-center mb-3">Reset Password</h1>

                <form id="resetForm" novalidate>
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div class="mb-3">
                        <label class="form-label" for="resetEmail">Email</label>
                        <input type="email" name="email" id="resetEmail" class="form-control" placeholder="Your email" autocomplete="email" required aria-describedby="emailError">
                        <div class="invalid-feedback" id="emailError" role="alert" aria-live="polite"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">New password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" class="form-control" placeholder="New password" autocomplete="new-password" required minlength="8" aria-describedby="passwordError">
                            <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)" aria-label="Show or hide new password">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback" id="passwordError" role="alert" aria-live="polite"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Confirm password</label>
                        <div class="input-group">
                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" placeholder="Confirm password" autocomplete="new-password" required aria-describedby="password_confirmationError">
                            <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password_confirmation', this)" aria-label="Show or hide password confirmation">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback" id="password_confirmationError" role="alert" aria-live="polite"></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="resetBtn">Reset Password</button>
                </form>

                <div class="text-center mt-3">
                    <a href="{{ route('login', absolute: false) }}">Back to Login</a>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="slb-toast-stack" id="toastContainer"></div>

<script>
function togglePassword(id, el){
    const input = document.getElementById(id);
    const icon = el.querySelector('i');
    if(!input || !icon) return;

    if(input.type === 'password'){
        input.type = 'text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

function showResetFieldErrors(errors) {
    const map = {
        email: ['resetEmail', 'emailError'],
        password: ['password', 'passwordError'],
        password_confirmation: ['password_confirmation', 'password_confirmationError'],
    };
    Object.keys(errors || {}).forEach(function (field) {
        const pair = map[field];
        if (!pair) return;
        const input = document.getElementById(pair[0]);
        const feedback = document.getElementById(pair[1]);
        const message = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
        if (input) {
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
        }
        if (feedback) {
            feedback.textContent = message;
            feedback.classList.add('d-block');
        }
    });
}

function showResetToast(message, success) {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        if (typeof slbAlert === 'function') {
            slbAlert({ icon: success ? 'success' : 'error', title: success ? 'Password reset' : 'Something went wrong', text: message });
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

document.getElementById('resetForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const resetBtn = document.getElementById('resetBtn');
    if(resetBtn.disabled) return;
    resetBtn.disabled = true;
    resetBtn.innerText = 'Resetting...';

    document.querySelectorAll('#resetForm .form-control').forEach(function (input) {
        input.classList.remove('is-invalid');
        input.removeAttribute('aria-invalid');
    });
    document.querySelectorAll('#emailError, #passwordError, #password_confirmationError').forEach(function (el) {
        el.textContent = '';
        el.classList.remove('d-block');
    });

    const formData = new FormData(this);
    let res;
    let data;

    try {
        res = await fetch("{{ route('password.update', absolute: false) }}", {
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
        showResetToast(
            (typeof slbHttpMessage === 'function')
                ? slbHttpMessage({ status: res && res.status ? res.status : 0 }, 'Please try again in a moment.')
                : 'Please try again in a moment.',
            false
        );
        resetBtn.disabled = false;
        resetBtn.innerText = 'Reset Password';
        return;
    }

    if (data.status === 'validation') {
        showResetFieldErrors(data.errors);
        const firstBag = data.errors ? Object.values(data.errors)[0] : null;
        const firstError = Array.isArray(firstBag) ? firstBag[0] : (firstBag || data.message || 'Please fix the highlighted fields and try again.');
        showResetToast(firstError, false);
    } else {
        const ok = data.status === 'success';
        const message = ok
            ? (data.message || 'Password has been reset successfully.')
            : ((typeof slbHttpMessage === 'function')
                ? slbHttpMessage({ status: res.status, data: data }, data.message || 'Invalid token or email.')
                : (data.message || 'Invalid token or email.'));
        showResetToast(message, ok);

        if (ok) {
            this.reset();
            setTimeout(()=>{ window.location.href = "{{ route('login', absolute: false) }}"; }, 1500);
        }
    }

    resetBtn.disabled = false;
    resetBtn.innerText = 'Reset Password';
});
</script>
@endsection
