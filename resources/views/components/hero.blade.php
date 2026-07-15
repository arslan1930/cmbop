<section style="position:relative; width:100%; padding:80px 0 60px; margin-top:80px; overflow:hidden; background:linear-gradient(180deg, #f0f5ff 0%, #f5faff 100%); min-height:700px;">

  <!-- ==================== BACKGROUND SHAPES ==================== -->

  <!-- Big Red Circle - Left -->
  <div style="position:absolute; top:30%; left:-180px; width:380px; height:380px; border-radius:50%; background:#FF4757; opacity:0.85; z-index:1;"></div>
  <div style="position:absolute; top:32%; left:-160px; width:340px; height:340px; border-radius:50%; background:#f0f5ff; z-index:1;"></div>

  <!-- Yellow Circle - Bottom Right -->
  <div style="position:absolute; bottom:-120px; right:-100px; width:300px; height:300px; border-radius:50%; background:#FFD93D; opacity:0.9; z-index:1;"></div>

  <!-- Teal Ring - Right Mid -->
  <div style="position:absolute; top:45%; right:8%; width:80px; height:80px; border-radius:50%; border:14px solid #4ECDCB; opacity:0.9; z-index:1;"></div>

  <!-- Red Ring - Right Bottom -->
  <div style="position:absolute; bottom:25%; right:15%; width:60px; height:60px; border-radius:50%; border:10px solid #FF4757; opacity:0.85; z-index:1;"></div>

  <!-- Decorative Line Pattern - Right Side -->
  <svg style="position:absolute; top:20%; right:0; width:300px; height:400px; opacity:0.15; z-index:1;" viewBox="0 0 300 400" fill="none">
    <path d="M50,50 L150,50 L150,150 L250,150 L250,250 L150,250 L150,350" stroke="#4ECDCB" stroke-width="2" fill="none"/>
    <circle cx="50" cy="50" r="4" fill="#4ECDCB"/>
    <circle cx="150" cy="150" r="4" fill="#4ECDCB"/>
    <circle cx="250" cy="250" r="4" fill="#4ECDCB"/>
    <polygon points="150,340 145,350 155,350" fill="#4ECDCB"/>
  </svg>

  <!-- Decorative Line Pattern - Left Side -->
  <svg style="position:absolute; top:60%; left:5%; width:200px; height:200px; opacity:0.12; z-index:1;" viewBox="0 0 200 200" fill="none">
    <path d="M20,100 L80,100 L80,40 L160,40" stroke="#FF4757" stroke-width="2" fill="none"/>
    <circle cx="20" cy="100" r="3" fill="#FF4757"/>
    <circle cx="160" cy="40" r="3" fill="#FF4757"/>
  </svg>

  <!-- Small Floating Dots -->
  <div style="position:absolute; top:15%; left:15%; width:12px; height:12px; border-radius:50%; background:#FFD93D; opacity:0.7; z-index:1;"></div>
  <div style="position:absolute; top:25%; right:25%; width:8px; height:8px; border-radius:50%; background:#4ECDCB; opacity:0.6; z-index:1;"></div>
  <div style="position:absolute; bottom:30%; left:30%; width:10px; height:10px; border-radius:50%; background:#FF4757; opacity:0.5; z-index:1;"></div>

  <!-- ==================== CONTENT ==================== -->
  <div class="container" style="position:relative; z-index:5; text-align:center; max-width:1200px; margin:0 auto; padding:0 20px;">

  <!-- Hero Title -->
<h1 style="margin:0 auto; font-size:3.25rem; line-height:1.15; font-weight:800; color:#1a1a2e; max-width:900px; letter-spacing:-1px;">
  {{ __('messages.hero_title') }}
</h1>

<!-- CTA Button -->
<a href="{{ localized_url('register') }}" class="hero-cta" style="display:inline-block; margin-top:1.5rem; padding:16px 48px; font-size:1rem; background:#4ECDCB; color:white; border-radius:50px; text-decoration:none; font-weight:700; box-shadow:0 8px 20px rgba(78,205,203,0.3); transition:all 0.3s ease;">
  {{ __('messages.get_started') }}
</a>

<!-- Tagline -->
<p style="margin-top:1.25rem; font-size:0.9rem; color:#888; max-width:680px; margin-left:auto; margin-right:auto; line-height:1.6;">
  {{ __('messages.hero_tagline') }}
</p>

    <!-- ==================== DASHBOARD MOCKUP (SKELETON) ==================== -->
    <div style="margin-top:3rem; position:relative; z-index:3;">

      <!-- Main Dashboard Card -->
      <div style="position:relative; width:92%; max-width:1050px; margin:0 auto; background:white; border-radius:14px; box-shadow:0 25px 60px rgba(0,0,0,0.12); overflow:hidden; z-index:2;">

        <!-- ===== TOP NAV BAR ===== -->
        <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 20px; background:white; border-bottom:1px solid #f0f0f0;">
          <!-- Left: back arrow + logo + switch button -->
          <div style="display:flex; align-items:center; gap:14px;">
            <div style="width:30px; height:30px; background:#f0f0f0; border-radius:6px; display:flex; align-items:center; justify-content:center;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            </div>
            <img src="{{ asset('assets/img/logo1.png') }}" alt="Seolinkbuildings Logo" style="height:32px; margin-right:0.5rem; transition: all 0.3s ease;">
            <div style="padding:5px 12px; background:#eef5ff; border:1px solid #d0e3ff; border-radius:5px;">
              <div style="width:90px; height:8px; background:#b8d4ff; border-radius:4px;"></div>
            </div>
          </div>

          <!-- Right: cart, theme, balance, avatar -->
<div style="display:flex; align-items:center; gap:10px;">

  <div style="width:32px; height:32px; background:#f5f5f5; border-radius:50%; display:flex; align-items:center; justify-content:center;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2">
      <circle cx="9" cy="21" r="1"/>
      <circle cx="20" cy="21" r="1"/>
      <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
    </svg>
  </div>

  <div style="width:32px; height:32px; background:#f5f5f5; border-radius:50%; display:flex; align-items:center; justify-content:center;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
  </div>

  <!-- Balance Box -->
  <div style="
      padding:7px 14px;
      background:#3b82f6;
      border-radius:6px;
      color:#fff;
      font-size:13px;
      font-weight:600;
      min-width:110px;
      text-align:center;
      font-family:Arial,sans-serif;
  ">
    €20.00 / €0.00
  </div>

  <div style="width:34px; height:34px; background:linear-gradient(135deg,#a78bfa,#8b5cf6); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600;">S</div>

</div>
        </div>

        <!-- ===== BODY: Sidebar + Main ===== -->
        <div style="display:flex; min-height:520px;">

          <!-- Sidebar -->
          <div style="width:200px; background:white; border-right:1px solid #f0f0f0; padding:18px 14px; flex-shrink:0;">
            <!-- Dashboard item (inactive) -->
            <div style="display:flex; align-items:center; gap:10px; padding:10px 12px; margin-bottom:4px;">
              <div style="width:14px; height:14px; background:#e0e0e0; border-radius:3px;"></div>
              <div style="height:9px; background:#e8e8e8; border-radius:4px; flex:1;"></div>
            </div>
            <!-- All Publishers (ACTIVE) -->
            <div style="display:flex; align-items:center; gap:10px; padding:10px 12px; background:#4ECDCB; border-radius:8px; margin-bottom:6px;">
              <div style="width:14px; height:14px; background:rgba(255,255,255,0.8); border-radius:3px;"></div>
              <div style="height:9px; background:rgba(255,255,255,0.85); border-radius:4px; flex:1;"></div>
            </div>
            <!-- Other items -->
            <div style="display:flex; align-items:center; gap:10px; padding:10px 12px; margin-bottom:4px;">
              <div style="width:14px; height:14px; background:#e0e0e0; border-radius:3px;"></div>
              <div style="height:9px; background:#e8e8e8; border-radius:4px; flex:1; max-width:60px;"></div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; padding:10px 12px; margin-bottom:4px;">
              <div style="width:14px; height:14px; background:#e0e0e0; border-radius:3px;"></div>
              <div style="height:9px; background:#e8e8e8; border-radius:4px; flex:1; max-width:80px;"></div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; padding:10px 12px;">
              <div style="width:14px; height:14px; background:#e0e0e0; border-radius:3px;"></div>
              <div style="height:9px; background:#e8e8e8; border-radius:4px; flex:1; max-width:70px;"></div>
            </div>
          </div>

          <!-- Main Content - All Publishers Page -->
          <div style="flex:1; padding:22px 24px; background:#f7f8fa;">

            <!-- Page heading -->
            <div style="text-align:left; margin-bottom:6px;">
              <div style="height:18px; width:200px; background:#d8d8dc; border-radius:5px;"></div>
            </div>
            <div style="text-align:left; margin-bottom:18px;">
              <div style="height:9px; width:420px; background:#e8e8ec; border-radius:4px;"></div>
            </div>

            <!-- ===== FILTER PANEL ===== -->
            <div style="background:white; padding:16px; border-radius:10px; border:1px solid #eef0f3; margin-bottom:16px;">
              <!-- Row 1: 5 filter fields -->
              <div style="display:grid; grid-template-columns:repeat(5, 1fr); gap:10px; margin-bottom:12px;">
                <div>
                  <div style="height:8px; width:50px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                </div>
                <div>
                  <div style="height:8px; width:55px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px; display:flex; align-items:center; justify-content:flex-end; padding:0 8px;">
                    <span style="color:#bbb; font-size:0.65rem;">▾</span>
                  </div>
                </div>
                <div>
                  <div style="height:8px; width:60px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px; display:flex; align-items:center; justify-content:flex-end; padding:0 8px;">
                    <span style="color:#bbb; font-size:0.65rem;">▾</span>
                  </div>
                </div>
                <div>
                  <div style="height:8px; width:65px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px; display:flex; align-items:center; justify-content:flex-end; padding:0 8px;">
                    <span style="color:#bbb; font-size:0.65rem;">▾</span>
                  </div>
                </div>
                <div>
                  <div style="height:8px; width:70px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="display:flex; gap:5px;">
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                  </div>
                </div>
              </div>

              <!-- Row 2: Range filters (DA, DR, Traffic) + Favorites/Blacklist -->
              <div style="display:grid; grid-template-columns:repeat(5, 1fr); gap:10px; margin-bottom:12px;">
                <div>
                  <div style="height:8px; width:55px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px; display:flex; align-items:center; justify-content:flex-end; padding:0 8px;">
                    <span style="color:#bbb; font-size:0.65rem;">▾</span>
                  </div>
                </div>
                <div>
                  <div style="height:8px; width:50px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px; display:flex; align-items:center; justify-content:flex-end; padding:0 8px;">
                    <span style="color:#bbb; font-size:0.65rem;">▾</span>
                  </div>
                </div>
                <div>
                  <div style="height:8px; width:55px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="display:flex; gap:5px;">
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                  </div>
                </div>
                <div>
                  <div style="height:8px; width:55px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="display:flex; gap:5px;">
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                  </div>
                </div>
                <div>
                  <div style="height:8px; width:75px; background:#d8d8dc; border-radius:3px; margin-bottom:6px;"></div>
                  <div style="display:flex; gap:5px;">
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                    <div style="flex:1; height:30px; background:#f5f5f5; border:1px solid #e8e8e8; border-radius:5px;"></div>
                  </div>
                </div>
              </div>

              <!-- Row 3: New Sites checkbox + buttons -->
              <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                  <div style="width:14px; height:14px; border:1.5px solid #ccc; border-radius:3px;"></div>
                  <div style="height:8px; width:80px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <div style="display:flex; gap:8px;">
                  <div style="padding:8px 22px; background:#3b82f6; border-radius:5px; display:flex; align-items:center; gap:6px;">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <div style="height:8px; width:35px; background:rgba(255,255,255,0.85); border-radius:3px;"></div>
                  </div>
                  <div style="padding:8px 22px; background:#6b7280; border-radius:5px; display:flex; align-items:center; gap:6px;">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    <div style="height:8px; width:35px; background:rgba(255,255,255,0.85); border-radius:3px;"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ===== PUBLISHERS TABLE ===== -->
            <div style="background:white; border-radius:10px; border:1px solid #eef0f3; overflow:hidden;">

              <!-- Table Header -->
              <div style="display:grid; grid-template-columns:1.6fr 1.2fr 1fr 0.7fr 0.7fr 0.9fr 1fr; padding:12px 16px; background:#fafbfc; border-bottom:1px solid #eef0f3; gap:8px; align-items:center;">
                <div style="height:8px; width:35px; background:#bcc1c8; border-radius:3px;"></div>
                <div style="height:8px; width:60px; background:#bcc1c8; border-radius:3px; margin:0 auto;"></div>
                <div style="height:8px; width:90px; background:#bcc1c8; border-radius:3px; margin:0 auto;"></div>
                <div style="height:8px; width:55px; background:#bcc1c8; border-radius:3px; margin:0 auto;"></div>
                <div style="height:8px; width:45px; background:#bcc1c8; border-radius:3px; margin:0 auto;"></div>
                <div style="height:8px; width:60px; background:#bcc1c8; border-radius:3px; margin:0 auto;"></div>
                <div style="height:8px; width:45px; background:#bcc1c8; border-radius:3px; margin:0 auto;"></div>
              </div>

              <!-- Row 1 -->
              <div style="display:grid; grid-template-columns:1.6fr 1.2fr 1fr 0.7fr 0.7fr 0.9fr 1fr; padding:14px 16px; border-bottom:1px solid #f5f5f5; gap:8px; align-items:center;">
                <!-- Site -->
                <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-start;">
                  <div style="display:flex; align-items:center; gap:6px;">
                    <div style="height:9px; width:60px; background:#d8d8dc; border-radius:3px;"></div>
                    <div style="display:inline-flex; align-items:center; justify-content:center; padding:2px 6px; background:#FF4757; border-radius:3px; line-height:1;">
    <span style="font-size:0.5rem; font-weight:700; color:#fff;">NEW</span>
</div>
                  </div>
                  <div style="height:6px; width:120px; background:#e8e8ec; border-radius:3px;"></div>
                  <div style="height:6px; width:90px; background:#e8e8ec; border-radius:3px;"></div>
                </div>
                <!-- Category -->
                <div style="display:flex; justify-content:center;">
                  <div style="padding:4px 10px; background:#e0f7f6; border-radius:4px;">
                    <div style="height:6px; width:70px; background:#4ECDCB; border-radius:3px; opacity:0.7;"></div>
                  </div>
                </div>
                <!-- Traffic -->
                <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="#f59e0b"><path d="M3 21h18v-2H3v2zm6-4h2V9H9v8zm4 0h2V5h-2v12zm4 0h2v-6h-2v6zm-12 0h2v-4H5v4z"/></svg>
                  <div style="height:8px; width:40px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <!-- DR -->
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <div style="width:14px; height:14px; background:#3b82f6; border-radius:3px; display:flex; align-items:center; justify-content:center; color:white; font-size:0.55rem; font-weight:800;">a</div>
                  <div style="height:8px; width:18px; background:#3b82f6; border-radius:3px; opacity:0.6;"></div>
                </div>
                <!-- DA -->
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="#FF4757"><path d="M12 2L2 7v10l10 5 10-5V7l-10-5z"/></svg>
                  <div style="height:8px; width:18px; background:#FF4757; border-radius:3px; opacity:0.6;"></div>
                </div>
                <!-- Language Flag -->
                <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                  <div style="width:24px; height:16px; background:linear-gradient(180deg,#3b5998 50%,#fff 50%); border-radius:2px; border:1px solid #e0e0e0;"></div>
                  <div style="height:6px; width:35px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <!-- Action: BUY BUTTON ONLY -->
                <div style="display:flex; justify-content:center;">
                  <div style="padding:7px 16px; background:#3b82f6; border-radius:5px; display:flex; align-items:center; gap:5px; box-shadow:0 2px 6px rgba(59,130,246,0.25);">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="white"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>
                    <span style="font-size:0.72rem; font-weight:700; color:white; white-space:nowrap;">Buy €1.00</span>
                  </div>
                </div>
              </div>

              <!-- Row 2 -->
              <div style="display:grid; grid-template-columns:1.6fr 1.2fr 1fr 0.7fr 0.7fr 0.9fr 1fr; padding:14px 16px; border-bottom:1px solid #f5f5f5; gap:8px; align-items:center;">
                <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-start;">
                  <div style="display:flex; align-items:center; gap:6px;">
                    <div style="height:9px; width:60px; background:#d8d8dc; border-radius:3px;"></div>
                    <div style="display:inline-flex; align-items:center; justify-content:center; padding:2px 6px; background:#FF4757; border-radius:3px; line-height:1;">
    <span style="font-size:0.5rem; font-weight:700; color:#fff;">NEW</span>
</div>

                  </div>
                  <div style="height:6px; width:120px; background:#e8e8ec; border-radius:3px;"></div>
                  <div style="height:6px; width:80px; background:#e8e8ec; border-radius:3px;"></div>
                </div>
                <div style="display:flex; justify-content:center;">
                  <div style="padding:4px 10px; background:#e0f7f6; border-radius:4px;">
                    <div style="height:6px; width:90px; background:#4ECDCB; border-radius:3px; opacity:0.7;"></div>
                  </div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="#f59e0b"><path d="M3 21h18v-2H3v2zm6-4h2V9H9v8zm4 0h2V5h-2v12zm4 0h2v-6h-2v6zm-12 0h2v-4H5v4z"/></svg>
                  <div style="height:8px; width:40px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <div style="width:14px; height:14px; background:#3b82f6; border-radius:3px; display:flex; align-items:center; justify-content:center; color:white; font-size:0.55rem; font-weight:800;">a</div>
                  <div style="height:8px; width:18px; background:#3b82f6; border-radius:3px; opacity:0.6;"></div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="#FF4757"><path d="M12 2L2 7v10l10 5 10-5V7l-10-5z"/></svg>
                  <div style="height:8px; width:18px; background:#FF4757; border-radius:3px; opacity:0.6;"></div>
                </div>
                <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                  <div style="width:24px; height:16px; background:linear-gradient(180deg,#c8102e 50%,#fff 50%); border-radius:2px; border:1px solid #e0e0e0;"></div>
                  <div style="height:6px; width:30px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <div style="display:flex; justify-content:center;">
                  <div style="padding:7px 16px; background:#3b82f6; border-radius:5px; display:flex; align-items:center; gap:5px; box-shadow:0 2px 6px rgba(59,130,246,0.25);">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="white"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1z"/></svg>
                    <span style="font-size:0.72rem; font-weight:700; color:white; white-space:nowrap;">Buy €100.00</span>
                  </div>
                </div>
              </div>

              <!-- Row 3 -->
              <div style="display:grid; grid-template-columns:1.6fr 1.2fr 1fr 0.7fr 0.7fr 0.9fr 1fr; padding:14px 16px; border-bottom:1px solid #f5f5f5; gap:8px; align-items:center;">
                <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-start;">
                  <div style="display:flex; align-items:center; gap:6px;">
                    <div style="height:9px; width:60px; background:#d8d8dc; border-radius:3px;"></div>
                    <div style="display:inline-flex; align-items:center; justify-content:center; padding:2px 6px; background:#FF4757; border-radius:3px; line-height:1;">
    <span style="font-size:0.5rem; font-weight:700; color:#fff;">NEW</span>
</div>
                  </div>
                  <div style="height:6px; width:120px; background:#e8e8ec; border-radius:3px;"></div>
                  <div style="height:6px; width:90px; background:#e8e8ec; border-radius:3px;"></div>
                </div>
                <div style="display:flex; justify-content:center;">
                  <div style="padding:4px 10px; background:#e0f7f6; border-radius:4px;">
                    <div style="height:6px; width:80px; background:#4ECDCB; border-radius:3px; opacity:0.7;"></div>
                  </div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="#f59e0b"><path d="M3 21h18v-2H3v2zm6-4h2V9H9v8zm4 0h2V5h-2v12zm4 0h2v-6h-2v6zm-12 0h2v-4H5v4z"/></svg>
                  <div style="height:8px; width:40px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <div style="width:14px; height:14px; background:#3b82f6; border-radius:3px; display:flex; align-items:center; justify-content:center; color:white; font-size:0.55rem; font-weight:800;">a</div>
                  <div style="height:8px; width:18px; background:#3b82f6; border-radius:3px; opacity:0.6;"></div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="#FF4757"><path d="M12 2L2 7v10l10 5 10-5V7l-10-5z"/></svg>
                  <div style="height:8px; width:18px; background:#FF4757; border-radius:3px; opacity:0.6;"></div>
                </div>
                <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                  <div style="width:24px; height:16px; background:linear-gradient(180deg,#FFC400 50%,#C60B1E 50%); border-radius:2px; border:1px solid #e0e0e0;"></div>
                  <div style="height:6px; width:35px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <div style="display:flex; justify-content:center;">
                  <div style="padding:7px 16px; background:#3b82f6; border-radius:5px; display:flex; align-items:center; gap:5px; box-shadow:0 2px 6px rgba(59,130,246,0.25);">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="white"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1z"/></svg>
                    <span style="font-size:0.72rem; font-weight:700; color:white; white-space:nowrap;">Buy €90.00</span>
                  </div>
                </div>
              </div>

              <!-- Row 4 -->
              <div style="display:grid; grid-template-columns:1.6fr 1.2fr 1fr 0.7fr 0.7fr 0.9fr 1fr; padding:14px 16px; gap:8px; align-items:center;">
                <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-start;">
                  <div style="height:9px; width:60px; background:#d8d8dc; border-radius:3px;"></div>
                  <div style="height:6px; width:120px; background:#e8e8ec; border-radius:3px;"></div>
                  <div style="height:6px; width:90px; background:#e8e8ec; border-radius:3px;"></div>
                </div>
                <div style="display:flex; justify-content:center;">
                  <div style="padding:4px 10px; background:#e0f7f6; border-radius:4px;">
                    <div style="height:6px; width:85px; background:#4ECDCB; border-radius:3px; opacity:0.7;"></div>
                  </div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="#f59e0b"><path d="M3 21h18v-2H3v2zm6-4h2V9H9v8zm4 0h2V5h-2v12zm4 0h2v-6h-2v6zm-12 0h2v-4H5v4z"/></svg>
                  <div style="height:8px; width:40px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <div style="width:14px; height:14px; background:#3b82f6; border-radius:3px; display:flex; align-items:center; justify-content:center; color:white; font-size:0.55rem; font-weight:800;">a</div>
                  <div style="height:8px; width:18px; background:#3b82f6; border-radius:3px; opacity:0.6;"></div>
                </div>
                <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="#FF4757"><path d="M12 2L2 7v10l10 5 10-5V7l-10-5z"/></svg>
                  <div style="height:8px; width:18px; background:#FF4757; border-radius:3px; opacity:0.6;"></div>
                </div>
                <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                  <div style="width:24px; height:16px; background:linear-gradient(180deg,#FF0000 50%,#fff 50%); border-radius:2px; border:1px solid #e0e0e0;"></div>
                  <div style="height:6px; width:35px; background:#d8d8dc; border-radius:3px;"></div>
                </div>
                <div style="display:flex; justify-content:center;">
                  <div style="padding:7px 16px; background:#3b82f6; border-radius:5px; display:flex; align-items:center; gap:5px; box-shadow:0 2px 6px rgba(59,130,246,0.25);">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="white"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1z"/></svg>
                    <span style="font-size:0.72rem; font-weight:700; color:white; white-space:nowrap;">Buy €100.00</span>
                  </div>
                </div>
              </div>

            </div>

          </div>
        </div>
      </div>

      <!-- ==================== FLOATING LABEL BADGES ==================== -->

      <!-- Verified Publishers - Top Left -->
      <div class="float-badge" style="position:absolute; top:18%; left:5%; background:white; border:2px solid #4ECDCB; padding:10px 18px; border-radius:8px; z-index:10; animation:floatUp 4s ease-in-out infinite;">
        <span style="font-size:0.85rem; font-weight:700; color:#4ECDCB; white-space:nowrap;">Verified Publishers</span>
      </div>

      <!-- Smart Filters - Top Right -->
      <div class="float-badge" style="position:absolute; top:14%; right:8%; background:white; border:2px solid #4ECDCB; padding:10px 18px; border-radius:8px; z-index:10; animation:floatDown 5s ease-in-out infinite;">
        <span style="font-size:0.85rem; font-weight:700; color:#4ECDCB; white-space:nowrap;">Smart Filters</span>
      </div>

      <!-- Detailed Insights - Mid Left -->
      <div class="float-badge" style="position:absolute; top:48%; left:3%; background:white; border:2px solid #4ECDCB; padding:10px 18px; border-radius:8px; z-index:10; animation:floatDown 4.5s ease-in-out infinite;">
        <span style="font-size:0.85rem; font-weight:700; color:#4ECDCB; white-space:nowrap;">Detailed Insights</span>
      </div>

      <!-- Best Prices - Mid Right -->
      <div class="float-badge" style="position:absolute; top:42%; right:4%; background:white; border:2px solid #4ECDCB; padding:10px 18px; border-radius:8px; z-index:10; animation:floatUp 5.5s ease-in-out infinite;">
        <span style="font-size:0.85rem; font-weight:700; color:#4ECDCB; white-space:nowrap;">Best Prices</span>
      </div>

      <!-- Live Stats - Bottom Right -->
      <div class="float-badge" style="position:absolute; bottom:12%; right:10%; background:white; border:2px solid #4ECDCB; padding:10px 18px; border-radius:8px; z-index:10; animation:floatUp 4.8s ease-in-out infinite;">
        <span style="font-size:0.85rem; font-weight:700; color:#4ECDCB; white-space:nowrap;">Live Stats</span>
      </div>

    </div>

  </div>
</section>

<style>
  @keyframes floatUp {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-12px); }
  }
  @keyframes floatDown {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(10px); }
  }
  .hero-cta:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(78,205,203,0.45) !important;
  }
  .float-badge {
    transition: transform 0.3s ease;
  }
  .float-badge:hover {
    transform: scale(1.08) !important;
  }

  @media (max-width: 992px) {
    .float-badge {
      display: none !important;
    }
  }
  @media (max-width: 768px) {
    section h1 {
      font-size: 2rem !important;
    }
  }
</style>