export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type ReleaseEntry = {
    version: string;
    released_at: string;
    title: string;
    summary: string;
    highlights: string[];
};
