<div class="modal fade" id="billingInfoModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fa fa-user-edit me-2"></i> Billing Information
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Please provide your billing information for the invoice.</p>
                
                <form id="billingForm">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Billing Name <span class="text-danger">*</span></label>
                            <input type="text" name="billing_name" id="billing_name" class="form-control" required>
                        </div>
                        <div class="col-md-6"><div class="modal fade" id="billingInfoModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fa fa-user-edit me-2"></i> Billing Information
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Please provide your billing information for the invoice.</p>
                
                <form id="billingForm">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Billing Name <span class="text-danger">*</span></label>
                            <input type="text" name="billing_name" id="billing_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company Name</label>
                            <input type="text" name="company_name" id="company_name" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                            <select name="country" id="country" class="form-select" required>
                                <option value="">Select Country</option>
                                <option value="United States">United States</option>
                                <option value="United Kingdom">United Kingdom</option>
                                <option value="Germany">Germany</option>
                                <option value="France">France</option>
                                <option value="Italy">Italy</option>
                                <option value="Spain">Spain</option>
                                <option value="Netherlands">Netherlands</option>
                                <option value="Belgium">Belgium</option>
                                <option value="Austria">Austria</option>
                                <option value="Switzerland">Switzerland</option>
                                <option value="Pakistan">Pakistan</option>
                                <option value="India">India</option>
                                <option value="UAE">UAE</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">State/Province</label>
                            <input type="text" name="state" id="state" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" id="city" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Postal Code</label>
                            <input type="text" name="postal_code" id="postal_code" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea name="address" id="address" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">VAT Number</label>
                            <input type="text" name="vat_number" id="vat_number" class="form-control">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBillingInfo">
                    <i class="fa fa-save"></i> Save & Continue
                </button>
            </div>
        </div>
    </div>
</div>
                            <label class="form-label">Company Name</label>
                            <input type="text" name="company_name" id="company_name" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                           <input type="text" name="country" id="country" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">State/Province</label>
                            <input type="text" name="state" id="state" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" id="city" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Postal Code</label>
                            <input type="text" name="postal_code" id="postal_code" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea name="address" id="address" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">VAT Number</label>
                            <input type="text" name="vat_number" id="vat_number" class="form-control">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBillingInfo">
                    <i class="fa fa-save"></i> Save & Continue
                </button>
            </div>
        </div>
    </div>
</div>