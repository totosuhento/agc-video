<?php
declare(strict_types=1);

/*
 * Preset niche: mengisi otomatis teks, filter, tema, dan kata kunci pencari channel.
 * Tambah niche baru cukup dengan menambah satu blok di sini.
 * niche = sebutan jamak (judul, meta), item = satu video. Wajib ada untuk 10 bahasa.
 */
const PRESETS = [
    'short_drama' => [
        'label' => 'Drama pendek (ReelShort, DramaBox, dll.)',
        'theme' => 'dark', 'accent' => '#ff4d6d', 'min_duration' => 120,
        'exclude' => ['#shorts', 'trailer', 'teaser'],
        'search' => ['en' => 'short drama full episodes', 'id' => 'drama pendek full episode', 'es' => 'drama corto episodios completos',
            'zh' => '短剧 全集', 'ko' => '숏드라마 전편', 'ja' => 'ショートドラマ 全話'],
        'niche' => ['en' => 'short dramas', 'id' => 'drama pendek', 'de' => 'Kurzdramen', 'es' => 'dramas cortos', 'fr' => 'séries courtes',
            'nl' => "korte drama's", 'zh' => '短剧', 'ko' => '숏드라마', 'ja' => 'ショートドラマ', 'hi' => 'शॉर्ट ड्रामा'],
        'item' => ['en' => 'short drama', 'id' => 'drama pendek', 'de' => 'Kurzdrama', 'es' => 'drama corto', 'fr' => 'série courte',
            'nl' => 'kort drama', 'zh' => '短剧', 'ko' => '숏드라마', 'ja' => 'ショートドラマ', 'hi' => 'शॉर्ट ड्रामा'],
    ],
    'kdrama' => [
        'label' => 'Drama Korea',
        'theme' => 'dark', 'accent' => '#8b5cf6', 'min_duration' => 600,
        'exclude' => ['#shorts', 'trailer', 'teaser', 'preview', 'behind the scenes'],
        'search' => ['en' => 'kdrama full episode official', 'id' => 'drama korea full episode sub indo', 'es' => 'kdrama capítulo completo',
            'ko' => '드라마 전편 다시보기', 'ja' => '韓国ドラマ 全話', 'zh' => '韩剧 全集'],
        'niche' => ['en' => 'Korean dramas', 'id' => 'drama Korea', 'de' => 'K-Dramen', 'es' => 'dramas coreanos', 'fr' => 'dramas coréens',
            'nl' => "Koreaanse drama's", 'zh' => '韩剧', 'ko' => '한국 드라마', 'ja' => '韓国ドラマ', 'hi' => 'कोरियाई ड्रामा'],
        'item' => ['en' => 'K-drama', 'id' => 'drama Korea', 'de' => 'K-Drama', 'es' => 'drama coreano', 'fr' => 'drama coréen',
            'nl' => 'Koreaans drama', 'zh' => '韩剧', 'ko' => '드라마', 'ja' => '韓国ドラマ', 'hi' => 'कोरियाई ड्रामा'],
    ],
    'horror' => [
        'label' => 'Horor (film pendek & cerita seram)',
        'theme' => 'dark', 'accent' => '#dc2626', 'min_duration' => 180,
        'exclude' => ['#shorts', 'trailer', 'teaser', 'reaction'],
        'search' => ['en' => 'horror short film', 'id' => 'film pendek horor', 'de' => 'Horror Kurzfilm', 'es' => 'cortometraje de terror',
            'fr' => "court métrage d'horreur", 'ja' => 'ホラー 短編映画', 'ko' => '공포 단편영화'],
        'niche' => ['en' => 'horror films', 'id' => 'film horor', 'de' => 'Horrorfilme', 'es' => 'películas de terror', 'fr' => "films d'horreur",
            'nl' => 'horrorfilms', 'zh' => '恐怖片', 'ko' => '공포 영화', 'ja' => 'ホラー映画', 'hi' => 'हॉरर फ़िल्में'],
        'item' => ['en' => 'horror film', 'id' => 'film horor', 'de' => 'Horrorfilm', 'es' => 'película de terror', 'fr' => "film d'horreur",
            'nl' => 'horrorfilm', 'zh' => '恐怖片', 'ko' => '공포 영화', 'ja' => 'ホラー映画', 'hi' => 'हॉरर फ़िल्म'],
    ],
    'anime' => [
        'label' => 'Anime (channel resmi seperti Muse, Ani-One)',
        'theme' => 'dark', 'accent' => '#22c55e', 'min_duration' => 600,
        'exclude' => ['#shorts', 'trailer', 'teaser', 'preview'],
        'search' => ['en' => 'anime full episode official', 'id' => 'anime full episode sub indo resmi', 'es' => 'anime episodio completo oficial',
            'ja' => 'アニメ 全話 公式', 'ko' => '애니 전편 공식'],
        'niche' => ['en' => 'anime episodes', 'id' => 'anime', 'de' => 'Anime-Folgen', 'es' => 'episodios de anime', 'fr' => "épisodes d'anime",
            'nl' => 'anime-afleveringen', 'zh' => '动漫', 'ko' => '애니메이션', 'ja' => 'アニメ', 'hi' => 'एनीमे'],
        'item' => ['en' => 'anime episode', 'id' => 'anime', 'de' => 'Anime-Folge', 'es' => 'episodio de anime', 'fr' => "épisode d'anime",
            'nl' => 'anime-aflevering', 'zh' => '动漫', 'ko' => '애니메이션', 'ja' => 'アニメ', 'hi' => 'एनीमे'],
    ],
    'software_tutorial' => [
        'label' => 'Tutorial software',
        'theme' => 'light', 'accent' => '#1f5eff', 'min_duration' => 90,
        'exclude' => ['#shorts', 'trailer', 'teaser', 'werbung', 'werbespot'],
        'search' => ['en' => 'software tutorial official', 'id' => 'tutorial software', 'de' => 'Software Tutorial Anleitung',
            'es' => 'tutorial software', 'fr' => 'tutoriel logiciel', 'nl' => 'software uitleg tutorial'],
        'niche' => ['en' => 'software tutorials', 'id' => 'tutorial software', 'de' => 'Software-Tutorials', 'es' => 'tutoriales de software',
            'fr' => 'tutoriels logiciels', 'nl' => 'softwaretutorials', 'zh' => '软件教程', 'ko' => '소프트웨어 튜토리얼',
            'ja' => 'ソフトウェアのチュートリアル', 'hi' => 'सॉफ़्टवेयर ट्यूटोरियल'],
        'item' => ['en' => 'tutorial', 'id' => 'tutorial', 'de' => 'Tutorial', 'es' => 'tutorial', 'fr' => 'tutoriel', 'nl' => 'tutorial',
            'zh' => '教程', 'ko' => '튜토리얼', 'ja' => 'チュートリアル', 'hi' => 'ट्यूटोरियल'],
    ],
    'cooking' => [
        'label' => 'Resep & masakan',
        'theme' => 'light', 'accent' => '#ea580c', 'min_duration' => 120,
        'exclude' => ['#shorts', 'trailer', 'teaser'],
        'search' => ['en' => 'recipe cooking channel', 'id' => 'resep masakan', 'de' => 'Rezepte kochen', 'es' => 'recetas de cocina',
            'fr' => 'recettes de cuisine', 'nl' => 'recepten koken', 'ja' => 'レシピ 料理', 'ko' => '요리 레시피', 'zh' => '食谱 做法', 'hi' => 'रेसिपी हिंदी'],
        'niche' => ['en' => 'recipe videos', 'id' => 'video resep', 'de' => 'Rezeptvideos', 'es' => 'videos de recetas', 'fr' => 'vidéos de recettes',
            'nl' => "receptvideo's", 'zh' => '食谱视频', 'ko' => '레시피 영상', 'ja' => 'レシピ動画', 'hi' => 'रेसिपी वीडियो'],
        'item' => ['en' => 'recipe', 'id' => 'resep', 'de' => 'Rezept', 'es' => 'receta', 'fr' => 'recette', 'nl' => 'recept',
            'zh' => '食谱', 'ko' => '레시피', 'ja' => 'レシピ', 'hi' => 'रेसिपी'],
    ],
    'documentary' => [
        'label' => 'Dokumenter',
        'theme' => 'light', 'accent' => '#0f766e', 'min_duration' => 600,
        'exclude' => ['#shorts', 'trailer', 'teaser'],
        'search' => ['en' => 'full documentary official', 'id' => 'film dokumenter lengkap', 'de' => 'Dokumentation ganze Folge',
            'es' => 'documental completo', 'fr' => 'documentaire complet', 'nl' => 'documentaire volledig'],
        'niche' => ['en' => 'documentaries', 'id' => 'film dokumenter', 'de' => 'Dokumentationen', 'es' => 'documentales', 'fr' => 'documentaires',
            'nl' => 'documentaires', 'zh' => '纪录片', 'ko' => '다큐멘터리', 'ja' => 'ドキュメンタリー', 'hi' => 'डॉक्यूमेंट्री'],
        'item' => ['en' => 'documentary', 'id' => 'dokumenter', 'de' => 'Dokumentation', 'es' => 'documental', 'fr' => 'documentaire',
            'nl' => 'documentaire', 'zh' => '纪录片', 'ko' => '다큐멘터리', 'ja' => 'ドキュメンタリー', 'hi' => 'डॉक्यूमेंट्री'],
    ],
    'custom' => [
        'label' => 'Lainnya (isi sendiri)',
        'theme' => 'dark', 'accent' => '#ff4d6d', 'min_duration' => 120,
        'exclude' => ['#shorts'],
        'search' => [],
        'niche' => [], 'item' => [],
    ],
];

/** Target negara/audiens. Kode ISO → [nama, bahasa yang disarankan]. */
const COUNTRIES = [
    '' => ['Global / semua negara', 'en'],
    'US' => ['Amerika Serikat', 'en'], 'GB' => ['Inggris', 'en'], 'CA' => ['Kanada', 'en'], 'AU' => ['Australia', 'en'],
    'NZ' => ['Selandia Baru', 'en'], 'IE' => ['Irlandia', 'en'],
    'DE' => ['Jerman', 'de'], 'AT' => ['Austria', 'de'], 'CH' => ['Swiss', 'de'],
    'NL' => ['Belanda', 'nl'], 'BE' => ['Belgia', 'nl'],
    'FR' => ['Prancis', 'fr'],
    'ES' => ['Spanyol', 'es'], 'MX' => ['Meksiko', 'es'], 'AR' => ['Argentina', 'es'], 'CO' => ['Kolombia', 'es'], 'CL' => ['Cile', 'es'],
    'ID' => ['Indonesia', 'id'], 'MY' => ['Malaysia', 'en'], 'SG' => ['Singapura', 'en'], 'PH' => ['Filipina', 'en'],
    'IN' => ['India', 'hi'],
    'JP' => ['Jepang', 'ja'], 'KR' => ['Korea Selatan', 'ko'],
    'TW' => ['Taiwan', 'zh'], 'HK' => ['Hong Kong', 'zh'],
];

/** Terapkan preset ke konfigurasi situs (bahasa mengikuti site.lang). */
function apply_preset(array $config, string $presetId): array
{
    $p = PRESETS[$presetId] ?? PRESETS['custom'];
    $lang = $config['site']['lang'] ?? 'en';
    $config['site']['preset'] = isset(PRESETS[$presetId]) ? $presetId : 'custom';
    $config['site']['theme'] = $p['theme'];
    $config['site']['accent'] = $p['accent'];
    $config['site']['niche'] = $p['niche'][$lang] ?? ($p['niche']['en'] ?? '');
    $config['site']['item'] = $p['item'][$lang] ?? ($p['item']['en'] ?? '');
    $config['youtube']['min_duration'] = $p['min_duration'];
    $config['youtube']['exclude_keywords'] = $p['exclude'];
    $config['youtube']['include_keywords'] = [];
    return $config;
}

function preset_search_query(string $presetId, string $lang): string
{
    $p = PRESETS[$presetId] ?? PRESETS['custom'];
    return $p['search'][$lang] ?? ($p['search']['en'] ?? '');
}
