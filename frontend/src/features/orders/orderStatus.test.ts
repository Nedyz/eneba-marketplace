import { describe, expect, it } from 'vitest'
import type { OrderStatus } from '../../api/orders'
import { awaitsPayment, ORDERS_MAX_PAGE, parseOrdersPage, statusLabel, statusLine, statusTone } from './orderStatus'

const all: OrderStatus[] = ['pending', 'paying', 'paid', 'failed', 'expired', 'cancelled']

describe('statusLabel and statusTone', () => {
  it('gives every status its own label', () => {
    const labels = all.map(statusLabel)
    expect(new Set(labels).size).toBe(all.length)
    expect(statusLabel('pending')).toBe('Awaiting payment')
    expect(statusLabel('failed')).toBe('Payment failed')
  })

  it('groups statuses into done, waiting and ended', () => {
    expect(all.map(statusTone)).toEqual(['waiting', 'waiting', 'done', 'ended', 'ended', 'ended'])
  })
})

describe('awaitsPayment', () => {
  it('is true only while keys are reserved', () => {
    expect(all.filter(awaitsPayment)).toEqual(['pending', 'paying'])
  })
})

describe('statusLine', () => {
  it('thanks the buyer only right after paying', () => {
    expect(statusLine({ id: 7, status: 'paid' }, true)).toBe('Thank you! Order #7 is paid. Your keys are below.')
    expect(statusLine({ id: 7, status: 'paid' }, false)).toBe('Paid. Your keys are below.')
  })

  it('says nothing was charged when an order ended unpaid', () => {
    expect(statusLine({ id: 7, status: 'expired' }, false)).toContain('Nothing was charged')
    expect(statusLine({ id: 7, status: 'failed' }, false)).toContain('refunded')
  })
})

describe('parseOrdersPage', () => {
  it('reads a whole page number', () => {
    expect(parseOrdersPage('1')).toBe(1)
    expect(parseOrdersPage('3')).toBe(3)
  })

  it('falls back to page 1 for anything else', () => {
    for (const value of [null, '', '0', '-2', 'abc', '2.5', '1e3', ' 2', '9999999999']) {
      expect(parseOrdersPage(value)).toBe(1)
    }
  })

  it('caps the page at what the API accepts', () => {
    expect(parseOrdersPage(String(ORDERS_MAX_PAGE))).toBe(ORDERS_MAX_PAGE)
    expect(parseOrdersPage('123456789')).toBe(ORDERS_MAX_PAGE)
  })
})
