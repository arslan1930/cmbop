<div class="modal fade chat-modal" id="chatModal" tabindex="-1" aria-labelledby="chatModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="chatModalTitle">
                    <i class="fa fa-comments me-2"></i>
                    Order Chat - <span id="chatOrderNumber"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div id="chatOrderDetails" class="chat-order-details d-none" aria-live="polite"></div>
            <div class="modal-body p-0">
                <div id="chatMessages" class="chat-messages" role="log" aria-live="polite">
                    <div class="text-center text-muted py-5">
                        <i class="fa fa-spinner fa-spin fa-2x"></i>
                        <p class="mt-2 mb-0">Loading messages...</p>
                    </div>
                </div>
                <div class="chat-composer">
                    <div id="chatComposerNote" class="small text-muted px-3 pt-2 d-none" role="status"></div>
                    <form id="chatForm">
                        <input type="hidden" id="chatOrderId">
                        <div class="input-group">
                            <textarea id="chatMessageInput" class="form-control" rows="2" placeholder="Type your message..." aria-label="Chat message"></textarea>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-paper-plane"></i> Send
                            </button>
                        </div>
                        <small class="text-muted mt-1 d-block">Press Ctrl+Enter to send</small>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
