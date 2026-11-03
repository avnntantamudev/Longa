/** Texto sem acentos e em minúsculas, para comparar pesquisas ("Canalização" → "canalizacao"). */
export function normalize(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

/**
 * Todas as palavras da pesquisa aparecem em algum dos textos (ordem e acentos indiferentes).
 * Pesquisa vazia corresponde a tudo.
 */
export function matchesSearch(query: string, ...texts: Array<string | null | undefined>): boolean {
    const words = normalize(query).split(/\s+/).filter(Boolean);
    if (words.length === 0) return true;
    const haystack = normalize(texts.filter(Boolean).join(' '));
    return words.every((word) => haystack.includes(word));
}
