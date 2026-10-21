<?php
declare(strict_types=1);

/** Pembuat deskripsi unik (opsional). Mendukung Anthropic & semua API OpenAI-compatible. */
final class AI
{
    /** Untuk pengujian: fungsi pengganti panggilan API (prompt => teks). */
    public static ?Closure $fake = null;

    public static function describe(string $type, string $title, string $description, string $channel): string
    {
        $lang = [
            'id' => 'Indonesian', 'en' => 'English', 'de' => 'German (natural, fluent, informal "du")',
            'es' => 'Spanish', 'fr' => 'French', 'nl' => 'Dutch', 'zh' => 'Simplified Chinese',
            'ko' => 'Korean', 'ja' => 'Japanese', 'hi' => 'Hindi',
        ][site_lang()] ?? 'English';
        $country = strtoupper((string)cfg('site.country', ''));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $lang .= " for readers in country code $country (use local spelling and conventions)";
        }
        $niche = trim((string)cfg('site.niche', '')) ?: t('_niche');
        $kind = $type === 'series' ? "a playlist of videos about $niche" : "a video about $niche";
        $desc = mb_substr(clean_description($description), 0, 2500);
        $focus = preg_match('/drama/i', $niche)
            ? 'Describe the premise, the mood and the kind of viewer who will enjoy it. No spoilers.'
            : 'Explain what the viewer will learn or see, the main steps or topics covered, and who it is useful for.';
        $prompt = <<<P
        Write an original page description in $lang for $kind on a website that embeds official YouTube videos.

        Title: $title
        Official channel: $channel
        Original description from the channel:
        """
        $desc
        """

        Rules:
        - 120 to 180 words, 2 short paragraphs, plain text only.
        - Use only facts implied by the title and description. Do not invent names, features, numbers or steps.
        - $focus
        - No hashtags, no links, no emojis, no "download the app" calls to action.
        - Output only the description.
        P;
        return trim(self::complete($prompt));
    }

    /** Draf artikel (Markdown) dalam bahasa situs. Wajib dibaca & disunting manusia sebelum terbit. */
    public static function article(string $title, string $keyword, array $videoTitles, string $notes): string
    {
        $lang = self::language();
        $niche = trim((string)cfg('site.niche', '')) ?: t('_niche');
        $list = implode("\n", array_map(fn($t) => '- ' . $t, array_slice($videoTitles, 0, 15)));
        $prompt = <<<P
        Write a helpful, original blog article in $lang for a website about $niche.

        Article title: $title
        Main keyword: $keyword
        Videos available on the site that you may refer to by their exact titles:
        $list

        Notes from the editor:
        $notes

        Rules:
        - 700 to 1000 words. Use Markdown: "## " for section headings, short paragraphs, bullet lists where useful.
        - Be genuinely useful: explain, compare, give tips or a watching order. Natural tone, no keyword stuffing.
        - Do NOT invent facts (release dates, cast, numbers, awards, prices). If unsure, stay general.
        - No links, no emojis, no "click here", no calls to install apps.
        - Do not repeat the title as a heading at the top. Output only the article body.
        P;
        return trim(self::complete($prompt, 2500));
    }

    /**
     * Tulis cepat: topik (boleh bahasa Indonesia) → artikel lengkap dalam bahasa situs.
     * @return array{title:string, excerpt:string, summary_id:string, body:string}
     */
    public static function articleFull(string $topic, string $keyword, array $videoTitles, string $notes): array
    {
        $lang = self::language();
        $niche = trim((string)cfg('site.niche', '')) ?: t('_niche');
        $list = implode("\n", array_map(fn($t) => '- ' . $t, array_slice($videoTitles, 0, 15))) ?: '(none)';
        $prompt = <<<P
        You write for a website about $niche. Write ONE original, genuinely helpful blog article in $lang.
        The editor described the topic (possibly in Indonesian; translate the idea, do not copy Indonesian words):
        "$topic"
        Main keyword (use naturally): $keyword
        Videos available on the site that you may mention by their exact titles:
        $list
        Extra notes from the editor: $notes

        Rules:
        - 700 to 1000 words, Markdown body: "## " section headings, short paragraphs, bullet lists where useful.
        - Explain, compare, give tips or a watching/learning order. Natural tone, no keyword stuffing.
        - Do NOT invent facts (release dates, cast, numbers, awards, prices). If unsure, stay general.
        - No links, no emojis, no "click here", no calls to install apps.

        Answer EXACTLY in this format:
        TITLE: <catchy, accurate title in $lang, max 70 characters>
        EXCERPT: <meta description in $lang, max 155 characters>
        SUMMARY_ID: <3-5 sentences in Indonesian summarising what the article says, for the editor to review>
        ---
        <article body in Markdown, in $lang, without repeating the title>
        P;
        $raw = str_replace("\r", '', trim(self::complete($prompt, 3000)));
        $out = ['title' => '', 'excerpt' => '', 'summary_id' => '', 'body' => ''];
        $parts = preg_split('/^\s*---\s*$/m', $raw, 2) ?: [$raw];
        $head = $parts[0];
        $out['body'] = trim($parts[1] ?? '');
        foreach (['title' => 'TITLE', 'excerpt' => 'EXCERPT', 'summary_id' => 'SUMMARY_ID'] as $k => $label) {
            if (preg_match('/^\s*' . $label . ':\s*(.+?)\s*(?=^\s*(?:TITLE|EXCERPT|SUMMARY_ID):|\z)/ms', $head, $m)) {
                $out[$k] = trim((string)preg_replace('/\s+/u', ' ', $m[1]), " \"'*");
            }
        }
        if ($out['body'] === '' || $out['title'] === '') {
            throw new RuntimeException('format jawaban AI tidak sesuai, coba lagi');
        }
        return $out;
    }

    /** Terjemahkan teks ke bahasa Indonesia agar editor bisa mengecek isi artikel berbahasa asing. */
    public static function toIndonesian(string $text): string
    {
        $text = mb_substr($text, 0, 12000);
        $prompt = "Translate the following Markdown article into natural Indonesian. Keep the Markdown structure. Output only the translation.\n\n" . $text;
        return trim(self::complete($prompt, 3500));
    }

    private static function language(): string
    {
        $lang = [
            'id' => 'Indonesian', 'en' => 'English', 'de' => 'German (natural, fluent, informal "du")',
            'es' => 'Spanish', 'fr' => 'French', 'nl' => 'Dutch', 'zh' => 'Simplified Chinese',
            'ko' => 'Korean', 'ja' => 'Japanese', 'hi' => 'Hindi',
        ][site_lang()] ?? 'English';
        $country = strtoupper((string)cfg('site.country', ''));
        return preg_match('/^[A-Z]{2}$/', $country) ? "$lang for readers in country code $country (use local spelling and conventions)" : $lang;
    }

    private static function complete(string $prompt, int $maxTokens = 700): string
    {
        if (self::$fake) {
            return (string)(self::$fake)($prompt);
        }
        $key = (string)cfg('ai.api_key', '');
        $model = (string)cfg('ai.model', '');
        if ($key === '' || $model === '') {
            throw new RuntimeException('ai.api_key / ai.model belum diisi');
        }
        if (cfg('ai.provider') === 'anthropic') {
            $res = self::post(cfg('ai.endpoint') ?: 'https://api.anthropic.com/v1/messages', [
                'x-api-key: ' . $key, 'anthropic-version: 2023-06-01',
            ], ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
            return (string)($res['content'][0]['text'] ?? '');
        }
        $res = self::post(cfg('ai.endpoint') ?: 'https://api.openai.com/v1/chat/completions', [
            'Authorization: Bearer ' . $key,
        ], ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
        return (string)($res['choices'][0]['message']['content'] ?? '');
    }

    private static function post(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 150,
            CURLOPT_HTTPHEADER     => [...$headers, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('cURL: ' . $err);
        }
        $json = json_decode((string)$raw, true);
        if ($code >= 400 || !is_array($json)) {
            throw new RuntimeException("AI API $code: " . substr((string)$raw, 0, 300));
        }
        return $json;
    }
}
