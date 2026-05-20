@extends('layouts.app')

@section('title', 'Login - Seolinkbuildings')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10 py-5">
            <div class="card shadow rounded-3 overflow-hidden">
                <div class="row g-0">

                    {{-- Left Image --}}
                    <div class="col-md-7 d-none d-md-flex align-items-center justify-content-center bg-light">
                        <img src="{{ asset('assets/img/Login_market_img.png') }}" class="img-fluid w-100 p-4">
                    </div>

                    {{-- Right Form --}}
                    <div class="col-md-5 p-4 p-md-5">
                        <h2 class="text-center mb-4">Login</h2>

                        <form id="loginForm">
                            @csrf

                            {{-- Email --}}
                            <div class="mb-3">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control" required>
                                <div class="invalid-feedback" id="emailError"></div>
                            </div>

                            {{-- Password --}}
                            <div class="mb-3">
                                <label>Password</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="password" class="form-control" required>
                                    <span class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </span>
                                </div>
                                <div class="invalid-feedback" id="passwordError"></div>
                            </div>

                            {{-- Forgot Password --}}
                            <div class="mb-3 text-end">
                                <a href="{{ route('password.request') }}" class="text-decoration-underline">Forgot Password?</a>
                            </div>

                            {{-- reCAPTCHA --}}
                            <!-- <div class="mb-3">
                                <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                            </div> -->

                            {{-- Submit --}}
                            <button class="btn btn-primary w-100">Login</button>

                            {{-- Divider --}}
                            <div class="position-relative my-4">
                                <hr>
                                <div class="position-absolute top-50 start-50 translate-middle bg-white px-3" style="margin-top: -0.5px;">
                                    <span class="text-muted">or</span>
                                </div>
                            </div>

                            {{-- Google Login Button --}}
                            <a href="{{ route('auth.google') }}" class="btn btn-outline-danger w-100 d-flex align-items-center justify-content-center gap-2">
                                <svg width="20" height="20" viewBox="0 0 24 24">
                                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                </svg>
                                Continue with Google
                            </a>

                            {{-- Resend Verification --}}
                            <div class="text-center mt-3" id="resendDiv" style="display:none;">
                                <button type="button" class="btn btn-link p-0" id="resendBtn">Resend Verification Email</button>
                            </div>

                            {{-- Register --}}
                            <div class="text-center mt-3">
                                Don’t have an account?
                                <a href="{{ route('register') }}" class="text-decoration-underline">Register Here</a>
                            </div>

                            {{-- Back Home --}}
                            <div class="mt-4">
                                <a href="{{ url('/') }}" class="text-decoration-underline">← Back to Home</a>
                            </div>

                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

{{-- Toast --}}
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<script>
// Toggle password
function togglePassword(id, el){
    const input = document.getElementById(id);
    const icon = el.querySelector('i');

    if(input.type === 'password'){
        input.type = 'text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

// Submit
document.getElementById('loginForm').addEventListener('submit', async function(e){
    e.preventDefault();

    document.querySelectorAll('.form-control').forEach(i=>i.classList.remove('is-invalid'));

    const formData = new FormData(this);

    const res = await fetch("{{ route('login.post') }}", {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: formData
    });

    let data;
    try {
        data = await res.json();
    } catch (e) {
        alert("Server error occurred.");
        return;
    }

    const toastContainer = document.getElementById('toastContainer');
    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-white border-0';

    if(data.status === 'success'){
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${data.message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-success');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

        setTimeout(() => {
            window.location.href = data.redirect;
        }, 1500);

    } else if(data.status === 'validation'){
        // Show first validation error
        const firstError = Object.values(data.errors)[0][0];
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${firstError}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-danger');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

    } else if(data.status === 'unverified'){
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${data.message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-warning');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

        // Show resend verification button
        const resendDiv = document.getElementById('resendDiv');
        resendDiv.style.display = 'block';
        const resendBtn = document.getElementById('resendBtn');

        resendBtn.onclick = async function(){
            if(!data.email) return;

            // Show "sending" toast immediately
            const sendingToast = document.createElement('div');
            sendingToast.className = 'toast align-items-center text-white border-0 bg-info';
            sendingToast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">Sending verification email...</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            toastContainer.appendChild(sendingToast);
            const sendingToastInstance = new bootstrap.Toast(sendingToast);
            sendingToastInstance.show();

            try {
                const emailData = new FormData();
                emailData.append('email', data.email);

                const res2 = await fetch("{{ route('verification.resend') }}", {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: emailData
                });

                const result = await res2.json();

                // Hide the "sending" toast
                sendingToastInstance.hide();

                // Show final result toast
                const toast2 = document.createElement('div');
                toast2.className = 'toast align-items-center text-white border-0';
                toast2.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">${result.message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                `;
                toast2.classList.add(result.status === 'success' ? 'bg-success' : 'bg-danger');
                toastContainer.appendChild(toast2);
                new bootstrap.Toast(toast2).show();

            } catch (err) {
                sendingToastInstance.hide();
                const toast2 = document.createElement('div');
                toast2.className = 'toast align-items-center text-white border-0 bg-danger';
                toast2.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">Failed to send email. Please try again.</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                `;
                toastContainer.appendChild(toast2);
                new bootstrap.Toast(toast2).show();
            }
        };

    } else {
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${data.message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-danger');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();
    }
});
</script>

@endsection