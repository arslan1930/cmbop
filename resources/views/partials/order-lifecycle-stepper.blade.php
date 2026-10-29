{{--
  Compact Placed › Accepted › Published › Approved stepper.
  Props:
    $stepper  array from AdvertiserOrderStatus::lifecycleStepper() OR list of {key,label,state}
    $current  optional int 1–4 when only a step index is known
--}}
@php
    $steps = [];
    $current = (int) ($current ?? 0);
    if (isset($stepper) && is_array($stepper)) {
        if (isset($stepper['steps']) && is_array($stepper['steps'])) {
            $steps = $stepper['steps'];
            $current = (int) ($stepper['current'] ?? $current);
        } else {
            $steps = $stepper;
        }
    }
    if ($steps === [] && $current >= 1) {
        $labels = ['Placed', 'Accepted', 'Published', 'Approved'];
        foreach ($labels as $i => $label) {
            $n = $i + 1;
            $steps[] = [
                'key' => strtolower($label),
                'label' => $label,
                'state' => $n < $current ? 'done' : ($n === $current ? 'current' : 'todo'),
            ];
        }
    }
    $fillPct = $current <= 1 ? 0 : (int) round((($current - 1) / 3) * 100);
@endphp
@if($steps !== [])
<div class="slb-stepper" data-step="{{ max(1, $current) }}" data-slb-stepper role="list" aria-label="Order progress">
    <div class="slb-stepper-track" aria-hidden="true"></div>
    <div class="slb-stepper-fill" aria-hidden="true" style="--slb-stepper-fill: {{ $fillPct }}%;"></div>
    @foreach($steps as $step)
        <div class="slb-step is-{{ $step['state'] ?? 'todo' }}" role="listitem">
            <i aria-hidden="true"></i>
            <span>{{ $step['label'] ?? '' }}</span>
        </div>
    @endforeach
</div>
@endif
