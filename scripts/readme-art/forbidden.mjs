/* ============================================================================
   Words the README art must never carry.

   The public-posture rule lets the art say the platform's public name,
   Citadel. What stays forbidden is what points at the inside: the two
   internal tools by name, and anything hostname-shaped. The character
   classes keep the forbidden literals out of this file, the way a squire
   keeps contraband out of the bag.
   ========================================================================== */

export const FORBIDDEN = /noug[a]t|snu[.]gs|docker[h]ost|\.(?:prod|cctest)\.wayne[.]edu/i;
