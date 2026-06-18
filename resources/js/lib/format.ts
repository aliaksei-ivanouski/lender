const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    published: 'Published',
    cancelled: 'Cancelled',
    sold_out: 'Sold out',
};

/**
 * Convert a raw DB status key into a human-readable label.
 * Known keys are mapped explicitly; unknown values have underscores/hyphens
 * replaced with spaces and the first letter capitalised.
 */
export function formatStatus(status: string): string {
    if (Object.prototype.hasOwnProperty.call(STATUS_LABELS, status)) {
        return STATUS_LABELS[status];
    }
    const spaced = status.replace(/[_-]/g, ' ');
    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
