@extends('layouts.app')

@section('title', 'Register - Seolinkbuildings')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10 py-5">
            <div class="card shadow rounded-3 overflow-hidden">
                <div class="row g-0">
                    {{-- Left Column: Image --}}
                    <div class="col-md-5 d-none d-md-flex align-items-center justify-content-center bg-light">
                        <img src="{{ asset('assets/img/Register_img.png') }}" alt="Register Image" class="img-fluid w-100 h-auto p-4">
                    </div>

                    {{-- Right Column: Registration Form --}}
                    <div class="col-md-7 p-4 p-md-5">
                        <h2 class="text-center mb-4">Sign Up for Free</h2>

                        <form id="registerForm" onsubmit="return false;">
                            @csrf

                            {{-- Name --}}
                            <div class="mb-3">
                                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" placeholder="Enter your name" required>
                                <div class="invalid-feedback" id="nameError"></div>
                            </div>

                            {{-- Email --}}
                            <div class="mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" id="email" class="form-control" placeholder="john@example.com" required>
                                <div class="invalid-feedback" id="emailError"></div>
                            </div>

                            {{-- Password + Confirm Password --}}
                            <div class="row g-2 mb-3">
                                <div class="col">
                                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" name="password" id="password" class="form-control pe-5" placeholder="Enter password" required>
                                        <span class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)"><i class="fa-solid fa-eye"></i></span>
                                        <div class="invalid-feedback" id="passwordError"></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <label for="password_confirmation" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control pe-5" placeholder="Confirm password" required>
                                        <span class="input-group-text" style="cursor:pointer" onclick="togglePassword('password_confirmation', this)"><i class="fa-solid fa-eye"></i></span>
                                        <div class="invalid-feedback" id="password_confirmationError"></div>
                                    </div>
                                </div>
                            </div>

                            {{-- Role Selection --}}
                            <div class="mb-3">
                                <label class="form-label">Register as <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2" id="roleSelect">
                                    <div class="role-card selected p-2 border rounded text-center flex-fill" data-value="advertiser">
                                        <i class="fa-solid fa-bullseye mb-1"></i><br>
                                        Advertiser
                                        <i class="fa-solid fa-check text-primary d-none mt-1"></i>
                                    </div>
                                    <div class="role-card p-2 border rounded text-center flex-fill" data-value="publisher">
                                        <i class="fa-solid fa-file-lines mb-1"></i><br>
                                        Publisher
                                        <i class="fa-solid fa-check text-primary d-none mt-1"></i>
                                    </div>
                                </div>
                                <input type="hidden" name="role" id="roleInput" value="">
                                <div class="invalid-feedback" id="roleError"></div>
                            </div>

                            {{-- Consents --}}
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="terms" id="terms" required>
                                    <label class="form-check-label" for="terms">
                                        <span class="text-danger">*</span> I agree to the <span class="text-decoration-underline"><a href="{{ url('/terms-and-services') }}" target="_blank">Terms and Services</a></span>.
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="marketing" id="marketing">
                                    <label class="form-check-label" for="marketing">
                                        I consent to receiving marketing communications, including information about services, offers, and promotional content.
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="newsletter" id="newsletter">
                                    <label class="form-check-label" for="newsletter">
                                        I would like to receive the company newsletter. For more info, see our <a href="{{ url('/privacy-policy') }}" target="_blank">Privacy Policy</a>.
                                    </label>
                                </div>
                            </div>

                            {{-- reCAPTCHA --}}
                            <!-- <div class="mb-3">
                                <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                            </div> -->

                            {{-- Submit Button --}}
                            <div class="d-flex gap-2 mb-3">
                                <button type="submit" class="btn btn-primary flex-fill" id="submitBtn">Register</button>
                                <a href="{{ url('/login') }}" class="btn btn-outline-secondary flex-fill">Login</a>
                            </div>

                            {{-- Divider --}}
                            <div class="position-relative my-4">
                                <hr>
                                <div class="position-absolute top-50 start-50 translate-middle bg-white px-3" style="margin-top: -0.5px;">
                                    <span class="text-muted">or</span>
                                </div>
                            </div>

                            {{-- Google Registration Button --}}
                            <div class="mb-3">
                                <a href="{{ route('auth.google') }}" class="btn btn-outline-danger w-100 d-flex align-items-center justify-content-center gap-2">
                                    <svg width="20" height="20" viewBox="0 0 24 24">
                                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                    </svg>
                                    Continue with Google
                                </a>
                            </div>

                            <div class="text-center">
                                <a href="{{ url('/') }}" class="text-decoration-underline">Back to Home</a>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Toast Container --}}
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<script src="https://www.google.com/recaptcha/api.js" async defer></script>

<script>
function togglePassword(id, iconSpan){
    const input = document.getElementById(id);
    const icon = iconSpan.querySelector('i');
    if(input.type==='password'){
        input.type='text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        input.type='password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

// Role card selection
document.querySelectorAll('#roleSelect .role-card').forEach(card=>{
    card.addEventListener('click', function(){
        document.querySelectorAll('#roleSelect .role-card').forEach(c=>{
            c.classList.remove('selected');
            c.querySelector('i.fa-check').classList.add('d-none');
        });
        this.classList.add('selected');
        this.querySelector('i.fa-check').classList.remove('d-none');
        document.getElementById('roleInput').value = this.dataset.value;
    });
});

// AJAX form submit
document.getElementById('registerForm').addEventListener('submit', async function(e){
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    if(submitBtn.disabled) return; // prevent multiple clicks
    submitBtn.disabled = true;
    submitBtn.innerText = 'Submitting...';

    document.querySelectorAll('.form-control').forEach(input=>{
        input.classList.remove('is-invalid');
    });

    ['nameError','emailError','passwordError','password_confirmationError','roleError'].forEach(id=>{
        const el = document.getElementById(id);
        if(el) el.innerText='';
    });

    const toastContainer = document.getElementById('toastContainer');

    // Validate role
    const role = document.getElementById('roleInput').value;
    if(!role){
        const toast = document.createElement('div');
        toast.className='toast align-items-center text-white border-0';
        toast.innerHTML=`<div class="d-flex"><div class="toast-body">Please select a role.</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        toast.classList.add('bg-warning');
        toastContainer.appendChild(toast);
        new bootstrap.Toast(toast,{delay:4000}).show();
        submitBtn.disabled=false;
        submitBtn.innerText='Register';
        return;
    }

    // Validate reCAPTCHA
    if(!grecaptcha.getResponse()){
        const toast = document.createElement('div');
        toast.className='toast align-items-center text-white border-0';
        toast.innerHTML=`<div class="d-flex"><div class="toast-body">Please complete the reCAPTCHA.</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        toast.classList.add('bg-warning');
        toastContainer.appendChild(toast);
        new bootstrap.Toast(toast,{delay:4000}).show();
        submitBtn.disabled=false;
        submitBtn.innerText='Register';
        return;
    }

    // Show sending toast
    const sendingToast = document.createElement('div');
    sendingToast.className='toast align-items-center text-white border-0';
    sendingToast.innerHTML=`<div class="d-flex"><div class="toast-body">Sending verification email...</div></div>`;
    sendingToast.classList.add('bg-info');
    toastContainer.appendChild(sendingToast);
    new bootstrap.Toast(sendingToast,{delay:3000}).show();

    const formData = new FormData(this);
    let data;
    try {
        const res = await fetch("{{ route('register') }}", {
            method:'POST',
            headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}' },
            body: formData
        });
        data = await res.json();
    } catch(e){
        alert('Server error occurred. Check logs.');
        submitBtn.disabled=false;
        submitBtn.innerText='Register';
        return;
    }

    sendingToast.remove();

    const toastEl=document.createElement('div');
    toastEl.className='toast align-items-center text-white border-0';
    toastEl.innerHTML=`<div class="d-flex"><div class="toast-body">${data.message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    toastContainer.appendChild(toastEl);

    if(data.status==='success'){
        toastEl.classList.add('bg-success');
        new bootstrap.Toast(toastEl,{delay:7000}).show();
        this.reset();
        document.getElementById('roleInput').value='';
        grecaptcha.reset();
    } else if(data.status==='error'){
        toastEl.classList.add('bg-danger');
        new bootstrap.Toast(toastEl,{delay:5000}).show();
        grecaptcha.reset();
    } else if(data.status==='validation'){
        for(let key in data.errors){
            const input=document.querySelector(`[name="${key}"]`);
            const errorDiv=document.getElementById(key+'Error');
            if(input) input.classList.add('is-invalid');
            if(errorDiv) errorDiv.innerText=data.errors[key][0];
        }
        grecaptcha.reset();
    }

    submitBtn.disabled=false;
    submitBtn.innerText='Register';
});
</script>
@endsection