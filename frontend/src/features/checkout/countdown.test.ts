import { describe, expect, it } from 'vitest'
import { deadlineFrom, formatCountdown, secondsUntil, timerAnnouncement } from './countdown'

describe('deadline', () => {
  it('is measured from when the answer arrived, on this clock', () => {
    const received = 1_000_000
    const deadline = deadlineFrom(900, received)

    expect(secondsUntil(deadline, received)).toBe(900)
    expect(secondsUntil(deadline, received + 60_000)).toBe(840)
  })

  it('rounds up, so the last second still shows 0:01', () => {
    expect(secondsUntil(10_000, 9_001)).toBe(1)
    expect(secondsUntil(10_000, 10_000)).toBe(0)
  })

  it('never goes negative', () => {
    expect(secondsUntil(10_000, 99_000)).toBe(0)
  })
})

describe('formatCountdown', () => {
  it('shows minutes and two-digit seconds', () => {
    expect(formatCountdown(905)).toBe('15:05')
    expect(formatCountdown(900)).toBe('15:00')
    expect(formatCountdown(59)).toBe('0:59')
    expect(formatCountdown(0)).toBe('0:00')
    expect(formatCountdown(-3)).toBe('0:00')
  })
})

describe('timerAnnouncement', () => {
  it('speaks only at the milestones', () => {
    expect(timerAnnouncement(900)).toBeNull()
    expect(timerAnnouncement(301)).toBeNull()
    expect(timerAnnouncement(300)).toBe('Less than 5 minutes left to pay.')
    expect(timerAnnouncement(61)).toBe('Less than 5 minutes left to pay.')
    expect(timerAnnouncement(60)).toBe('Less than 1 minute left to pay.')
    expect(timerAnnouncement(1)).toBe('Less than 1 minute left to pay.')
    expect(timerAnnouncement(0)).toBe('Your reservation has expired.')
  })
})
