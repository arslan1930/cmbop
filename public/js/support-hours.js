/**
 * Convert support-hours copy from UTC into the visitor's local timezone.
 * Nodes keep the UTC string as a fallback when Intl or the zone is missing.
 */
(function () {
  'use strict';

  var nodes = document.querySelectorAll('[data-support-hours]');
  if (!nodes.length) return;

  function parseClock(value) {
    var parts = String(value || '').split(':');
    var hour = parseInt(parts[0], 10);
    var minute = parseInt(parts[1] || '0', 10);
    if (isNaN(hour) || hour < 0 || hour > 23) return null;
    if (isNaN(minute) || minute < 0 || minute > 59) minute = 0;
    return { hour: hour, minute: minute };
  }

  function formatClock(date) {
    try {
      return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
      }).format(date);
    } catch (err) {
      var hour = date.getHours();
      var minute = date.getMinutes();
      var suffix = hour < 12 ? 'AM' : 'PM';
      var hour12 = hour % 12 || 12;
      var padded = minute < 10 ? '0' + minute : String(minute);
      return hour12 + ':' + padded + ' ' + suffix;
    }
  }

  function visitorTimeZone() {
    try {
      var zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      return zone && String(zone).trim() ? String(zone) : '';
    } catch (err) {
      return '';
    }
  }

  var zone = visitorTimeZone();
  if (!zone) return;

  nodes.forEach(function (el) {
    var start = parseClock(el.getAttribute('data-utc-start') || '09:00');
    var end = parseClock(el.getAttribute('data-utc-end') || '18:00');
    var days = el.getAttribute('data-days') || 'Mon–Fri';
    if (!start || !end) return;

    var now = new Date();
    var startUtc = new Date(Date.UTC(
      now.getUTCFullYear(),
      now.getUTCMonth(),
      now.getUTCDate(),
      start.hour,
      start.minute,
      0
    ));
    var endUtc = new Date(Date.UTC(
      now.getUTCFullYear(),
      now.getUTCMonth(),
      now.getUTCDate(),
      end.hour,
      end.minute,
      0
    ));

    el.textContent = days + ', ' + formatClock(startUtc) + '–' + formatClock(endUtc) + ' local';
    el.setAttribute('title', days + ', 9:00–18:00 UTC');
  });
})();
