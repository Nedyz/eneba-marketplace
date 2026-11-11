/**
 * Is `link` a path on this site? Used wherever a path comes from data rather
 * than from our own code and is then navigated to: banner links from the
 * database (D32) and the page to return to after signing in (D33).
 *
 * "/store?..." yes; "https://...", "//host" and "/\host" (which browsers also
 * read as another host) no.
 */
export function isInternalPath(link: string): boolean {
  return link.startsWith('/') && !link.startsWith('//') && !link.startsWith('/\\')
}
