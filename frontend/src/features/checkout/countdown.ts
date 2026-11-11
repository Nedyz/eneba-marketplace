/**
 * The reservation timer (D10, D34). The server sends `seconds_left` with each
 * order; the deadline is "when that answer arrived + seconds_left", measured
 * on this computer's own clock. A wrong system clock therefore shifts nothing,
 * unlike comparing the server's `expires_at` with Date.now().
 */

/** Milliseconds timestamp (this computer's clock) at which the reservation ends. */
export function deadlineFrom(secondsLeft: number, receivedAt: number): number {
  return receivedAt + secondsLeft * 1000
}

/** Whole seconds left, never negative. Rounded up: "0:01" until the very end. */
export function secondsUntil(deadline: number, now: number): number {
  return Math.max(0, Math.ceil((deadline - now) / 1000))
}

/** 905 -> "15:05", 59 -> "0:59". */
export function formatCountdown(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

/**
 * What a screen reader should hear at this moment. Announcing every second
 * would make the page unusable, so only a few milestones are spoken; the
 * visible timer is not a live region.
 */
export function timerAnnouncement(seconds: number): string | null {
  if (seconds <= 0) return 'Your reservation has expired.'
  if (seconds <= 60) return 'Less than 1 minute left to pay.'
  if (seconds <= 5 * 60) return 'Less than 5 minutes left to pay.'
  return null
}
