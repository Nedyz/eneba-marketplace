import type { HomeBlock } from '../../api/home'

/**
 * Pure rules of the server-driven homepage (D4, D32), kept apart from the
 * components so Vitest can test them without rendering.
 */

/**
 * Blocks the frontend knows how to render, in API order, each with its
 * component. Unknown types are dropped, so a block type added on the backend
 * before the frontend knows it is skipped instead of crashing the page.
 *
 * Object.hasOwn and not `registry[type]`: a plain lookup would find inherited
 * properties, so a type "constructor" or "toString" would return
 * Object.prototype's function and React would try to render it.
 */
export function renderableBlocks<C>(blocks: HomeBlock[], registry: Record<string, C>): { block: HomeBlock; Component: C }[] {
  return blocks.flatMap((block) => (Object.hasOwn(registry, block.type) ? [{ block, Component: registry[block.type] }] : []))
}
