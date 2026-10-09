const MINUTE = 60_000;

/** Whole minutes until the linking link expires, rounded up; 0 once expired. */
export function minutesLeft(expiresAt: string, now: Date = new Date()): number {
    const diff = new Date(expiresAt).getTime() - now.getTime();
    return diff <= 0 ? 0 : Math.ceil(diff / MINUTE);
}

/** "El enlace vence en 9 minutos." */
export function expiryText(minutes: number): string {
    if (minutes <= 0) return 'El enlace ya venció.';
    return `El enlace vence en ${minutes} ${minutes === 1 ? 'minuto' : 'minutos'}.`;
}
