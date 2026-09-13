{{-- Staff-reviewed chip for live unverified new listings.
     Verified rows keep the TXT Verified chip only. --}}
@if($site->showsStaffReviewedChip())
    <span class="site-chip site-chip--staff site-chip--status"
          data-glass-tip
          data-glass-tip-title="Staff reviewed"
          data-glass-tip-body="Our team activated this listing. It is not TXT verified yet. Ratings appear after completed orders."
          data-glass-tip-placement="top"
          aria-label="Staff reviewed">
        <i class="fa-solid fa-user-check" aria-hidden="true"></i>
        <span>Staff reviewed</span>
    </span>
@endif
