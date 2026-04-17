@extends('layouts.app')

@section('title', 'Reset Password')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 py-5">
            <div class="card p-4 shadow rounded-3">

                <h3 class="text-center mb-3">Reset Password</h3>

                <form id="resetForm">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="email" name="email" class="form-control mb-3" placeholder="Your email" required>
                    <input type="password" name="password" class="form-control mb-3" placeholder="New password" required>
                    <input type="password" name="password_confirmation" class="form-control mb-3" placeholder="Confirm password" required>

                    <button type="submit" class="btn btn-primary w-100" id="resetBtn">Reset Password</button>
                </form>

                <div class="text-center mt-3">
                    <a href="{{ route('login') }}">Back to Login</a>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<script>
document.getElementById('resetForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const resetBtn = document.getElementById('resetBtn');
    if(resetBtn.disabled) return;
    resetBtn.disabled = true;
    resetBtn.innerText = 'Resetting...';

    const formData = new FormData(this);
    let data;

    try {
        const res = await fetch("{{ route('password.update') }}", {
            method:'POST',
            headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}' },
            body: formData
        });
        data = await res.json();
    } catch(e){
        alert('Server error.');
        resetBtn.disabled = false;
        resetBtn.innerText = 'Reset Password';
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

    if(data.status==='success'){
        this.reset();
        setTimeout(()=>{ window.location.href = "{{ route('login') }}"; }, 1500);
    }

    resetBtn.disabled = false;
    resetBtn.innerText = 'Reset Password';
});
</script>
@endsection