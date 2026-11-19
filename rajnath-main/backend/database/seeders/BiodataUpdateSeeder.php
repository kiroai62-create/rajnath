<?php

namespace Database\Seeders;

use App\Domain\Showcase\Profile;
use App\Support\Settings\SettingsRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner's bio-data PDF and 16 trophy photos (18 Nov 2026), applied once (flag below):
 * - corrects seeded entries (no "1st Division" next to the Ph.D. or the Gold Medal, the Shalaka Pariksha
 *   as its own honour, award names as written on the trophies, books marked as in preparation);
 * - adds the awards, research papers and media roles from the bio-data, with the trophy photos;
 * - fills the Hindi title/detail of entries that have none;
 * - adds the "Award trophies at the Patna office" album.
 * A correction is applied only while the entry still has its seeded text, and Hindi only where it is
 * empty, so edits made in Admin › About & awards are kept.
 */
class BiodataUpdateSeeder extends Seeder
{
    public const FLAG = 'profile.biodata_2026_11_18';

    public const DIR = 'images/gallery/award-trophies-at-the-patna-office';

    private const KSDSU_HI = 'कामेश्वर सिंह दरभंगा संस्कृत विश्वविद्यालय';

    /**
     * Corrections: [section, current title, current detail (null = any), new values]. Values ending in "_hi" are
     * only written where the entry has no Hindi yet; "image" is a photo number from the trophy album.
     *
     * @var list<array{0: string, 1: string, 2: ?string, 3: array<string, string|int|null>}>
     */
    private const CORRECTIONS = [
        ['education', 'Ph.D. in Astrology', 'K.S.D.S.U., Darbhanga — 1st Division. Thesis: "Varahmihiracharyakrit Mayurchitraka: Pathlochan Purvak Samikshatmak Adhyayan"', [
            'detail' => 'K.S.D.S.U., Darbhanga. Thesis: "Varahmihiracharyakrit Mayurchitraka: Pathlochan Purvak Samikshatmak Adhyayan"',
            'title_hi' => 'ज्योतिष में पीएच.डी.', 'detail_hi' => self::KSDSU_HI.', दरभंगा। शोध-प्रबन्ध: "वराहमिहिराचार्यकृत मयूरचित्रक: पाठालोचनपूर्वक समीक्षात्मक अध्ययन"']],
        ['education', 'Acharya (Ganit Jyotish), Gold Medalist', 'K.S.D.S.U., Darbhanga — 1st Division', [
            'title' => 'Acharya (Ganit Jyotish) — University Gold Medalist', 'detail' => 'K.S.D.S.U., Darbhanga — topped the university',
            'title_hi' => 'आचार्य (गणित ज्योतिष) — विश्वविद्यालय स्वर्ण पदक', 'detail_hi' => self::KSDSU_HI.' — विश्वविद्यालय में सर्वोच्च स्थान']],
        ['education', 'Acharya (Phalit Jyotish)', null, ['title_hi' => 'आचार्य (फलित ज्योतिष)', 'detail_hi' => self::KSDSU_HI.' — प्रथम श्रेणी']],
        ['education', 'Shastri (Hons.)', null, ['title_hi' => 'शास्त्री (प्रतिष्ठा)', 'detail_hi' => self::KSDSU_HI]],
        ['education', 'Upashastri', null, ['title_hi' => 'उपशास्त्री', 'detail_hi' => self::KSDSU_HI.' — प्रथम श्रेणी']],
        ['education', 'Madhyama', null, ['title_hi' => 'मध्यमा', 'detail_hi' => 'बिहार संस्कृत शिक्षा बोर्ड, पटना — प्रथम श्रेणी']],

        ['award', 'Gold Medal, Acharya Pariksha (Ganit Jyotish)', 'K.S.D.S.U., Darbhanga; also 1st position, Shalaka Pariksha (1998)', [
            'title' => 'University Gold Medal — Acharya Pariksha (Ganit Jyotish)', 'detail' => 'K.S.D.S.U., Darbhanga — topped the university',
            'title_hi' => 'विश्वविद्यालय स्वर्ण पदक — आचार्य परीक्षा (गणित ज्योतिष)', 'detail_hi' => self::KSDSU_HI.' — विश्वविद्यालय में सर्वोच्च स्थान']],
        ['award', 'Jyotish Siromani International Award', 'Chennai', [
            'title' => 'Jyotish Shiromani Award', 'detail' => 'Tamil Nadu Astrological Convocation (IVAF), Chennai, 25 Feb 2024', 'image' => 12,
            'title_hi' => 'ज्योतिष शिरोमणि सम्मान', 'detail_hi' => 'तमिलनाडु एस्ट्रोलॉजिकल कन्वोकेशन (IVAF), चेन्नई, 25 फ़रवरी 2024']],
        ['award', 'Jyotish Samridhi Award', 'Tamil Nadu', [
            'title' => 'Jyotidhar Samrithi Award', 'detail' => 'Tamil Nadu Astrological Convocation (IVAF), Chennai, 25 Feb 2024', 'group' => 'international', 'image' => 14,
            'title_hi' => 'ज्योतिधर समृद्धि सम्मान', 'detail_hi' => 'तमिलनाडु एस्ट्रोलॉजिकल कन्वोकेशन (IVAF), चेन्नई, 25 फ़रवरी 2024']],
        ['award', 'Hind Gaurav Award', 'Indore', [
            'title' => 'Hind Gaurav Samman', 'detail' => '33rd International Jyotish, Vastu & Ayurveda Mahasammelan, Maa Sharda Jyotishdham Anusandhan Sansthanam, Indore', 'image' => 7,
            'title_hi' => 'हिन्द गौरव सम्मान', 'detail_hi' => '33वाँ अन्तर्राष्ट्रीय ज्योतिष, वास्तु एवं आयुर्वेद महासम्मेलन, माँ शारदा ज्योतिषधाम अनुसंधान संस्थानम्, इंदौर']],
        ['award', 'Golden Achievement Award', '28th International Conference, Kolkata', [
            'detail' => '28th International Astrological Conference, organised by A.R.P., Kolkata', 'group' => 'international',
            'title_hi' => 'गोल्डन अचीवमेंट अवार्ड', 'detail_hi' => '28वाँ अन्तर्राष्ट्रीय ज्योतिष सम्मेलन, ए.आर.पी., कोलकाता']],
        ['award', 'Vishwabandhu Award', '27th International Conference, Kolkata', [
            'detail' => '27th International Astrological Conference, organised by A.R.P., Kolkata', 'group' => 'international',
            'title_hi' => 'विश्वबन्धु सम्मान', 'detail_hi' => '27वाँ अन्तर्राष्ट्रीय ज्योतिष सम्मेलन, ए.आर.पी., कोलकाता']],
        ['award', 'Jyotish Shri Award', '44th National Astrology Seminar, Madhubani', [
            'detail' => 'National award, 44th National Astrology Seminar, Madhubani, Bihar',
            'title_hi' => 'ज्योतिष श्री सम्मान', 'detail_hi' => 'राष्ट्रीय सम्मान, 44वीं राष्ट्रीय ज्योतिष संगोष्ठी, मधुबनी, बिहार']],
        ['award', 'Professor Award 2026', null, ['title_hi' => 'प्रोफ़ेसर अवार्ड 2026',
            'detail_hi' => 'IVAF इंटरनेशनल वैदिक एस्ट्रोलॉजी फ़ेडरेशन, श्रीलंका सेक्रेड एस्ट्रोलॉजिकल सिम्पोज़ियम (ज्योतिष, वास्तु एवं रत्न) में सहभागिता हेतु, कैंडी, 8 सितम्बर 2026']],
        ['award', 'Global Astrological Iconic Award & Lifetime Achievement Honour', null, ['title_hi' => 'ग्लोबल एस्ट्रोलॉजिकल आइकॉनिक अवार्ड एवं लाइफटाइम अचीवमेंट सम्मान',
            'detail_hi' => 'सुब्रमण्यम एस्ट्रोलॉजिकल एंड स्पिरिचुअल कन्वोकेशन, मलेशिया']],
        ['award', 'US Global Excellence Award', null, ['title_hi' => 'यूएस ग्लोबल एक्सीलेंस अवार्ड', 'detail_hi' => 'नई दिल्ली']],
        ['award', 'Maharshi Gyan Ratan Award', null, ['title_hi' => 'महर्षि ज्ञान रत्न सम्मान', 'detail_hi' => 'वियतनाम']],
        ['award', 'Mithila Ratna Award', null, ['title_hi' => 'मिथिला रत्न सम्मान', 'detail_hi' => 'मधुबनी']],
        ['award', 'Jyotish Gyan Ratna Award', null, ['title_hi' => 'ज्योतिष ज्ञान रत्न सम्मान', 'detail_hi' => 'तिरुपति']],
        ['award', 'Jyotish Chakravarthy Award', null, ['title_hi' => 'ज्योतिष चक्रवर्ती सम्मान', 'detail_hi' => 'मुंबई']],

        ['position', 'Director — Jyotirveda Vigyan Sansthan', null, ['title_hi' => 'निदेशक — ज्योतिर्वेद विज्ञान संस्थान', 'detail_hi' => 'वैदिक विज्ञान में शोध एवं शिक्षा: कृषि, आपदा पूर्वानुमान और स्वास्थ्य।']],
        ['position', 'Founder & Director — Sanatan Vedic Gurukul, Madhubani', null, ['title_hi' => 'संस्थापक एवं निदेशक — सनातन वैदिक गुरुकुल, मधुबनी']],
        ['position', 'International Chief Consultant — International Astrology Federation (USA)', null, ['title_hi' => 'अन्तर्राष्ट्रीय मुख्य परामर्शदाता — इंटरनेशनल एस्ट्रोलॉजी फ़ेडरेशन (USA)']],
        ['position', 'Vice Chairman — World Climate Change Foundation, Bihar', null, ['title_hi' => 'उपाध्यक्ष — वर्ल्ड क्लाइमेट चेंज फ़ाउंडेशन, बिहार']],
        ['position', 'Jyotish & Karmkand consultant — Jyotish Mandap, Mahavir Mandir Trust, Patna', null, ['title_hi' => 'ज्योतिष एवं कर्मकाण्ड परामर्शदाता — ज्योतिष मण्डप, महावीर मन्दिर न्यास, पटना', 'detail_hi' => '17 वर्षों की सेवा।']],
        ['position', 'Faculty — Vinodanand Research Center, Patna', null, ['title_hi' => 'अध्यापन — विनोदानन्द शोध केन्द्र, पटना']],

        ['expertise', 'Vedic astrology (Jyotish)', null, ['title_hi' => 'वैदिक ज्योतिष']],
        ['expertise', 'Kundali analysis', null, ['title_hi' => 'कुण्डली विश्लेषण']],
        ['expertise', 'Kundali matching', null, ['title_hi' => 'कुण्डली मिलान']],
        ['expertise', 'Vastu Shastra', null, ['title_hi' => 'वास्तु शास्त्र']],
        ['expertise', 'Numerology', null, ['title_hi' => 'अंक ज्योतिष']],
        ['expertise', 'Palmistry', null, ['title_hi' => 'हस्तरेखा']],
        ['expertise', 'Muhurat', null, ['title_hi' => 'मुहूर्त']],
        ['expertise', 'Gemstone recommendation', null, ['title_hi' => 'रत्न परामर्श']],
        ['expertise', 'Karmkand and puja', null, ['title_hi' => 'कर्मकाण्ड एवं पूजा']],

        ['research', 'Jyotish Shastra ke Drishti se Bhukamp ka Purvanuman', 'Rashtriya Sanskrit Sansthan, Vedavyas Campus', [
            'detail' => 'Lecture, Rashtriya Sanskrit Sansthan (Deemed University), Vedavyas Campus, Balahar, H.P.',
            'title_hi' => 'ज्योतिष शास्त्र की दृष्टि से भूकम्प का पूर्वानुमान', 'detail_hi' => 'व्याख्यान, राष्ट्रीय संस्कृत संस्थान (मानित विश्वविद्यालय), वेदव्यास परिसर, बलाहर, हि.प्र.']],
        ['research', 'Kurma Chakra and Bhukamp research', null, ['title_hi' => 'कूर्म चक्र एवं भूकम्प पर शोध-पत्र', 'detail_hi' => 'काशी हिन्दू विश्वविद्यालय, वाराणसी']],
        ['research', 'Bhukamp ke Purvanuman ka Jyotishshastriya Anusandhan', 'K.S.D.S.U., Darbhanga', [
            'detail' => 'Special research paper, Jyotish Department, K.S.D.S.U., Darbhanga',
            'title_hi' => 'भूकम्प के पूर्वानुमान का ज्योतिषशास्त्रीय अनुसंधान', 'detail_hi' => 'विशेष शोध-पत्र, ज्योतिष विभाग, '.self::KSDSU_HI]],
        ['research', 'Papers at B.H.U. and the Oriental Conference, Tirupati', null, ['published' => 0]],

        ['book', 'Mayurchitraka Mimamsa — Varahamihira', null, ['title_hi' => 'मयूरचित्रक मीमांसा — वराहमिहिर']],
        ['book', 'Jyotirved Vigyan', null, ['title_hi' => 'ज्योतिर्वेद विज्ञान']],
        ['book', 'Jyotish Drishtya Mansik Rog Vimarsh', null, ['title_hi' => 'ज्योतिषदृष्ट्या मानसिक रोग विमर्श']],
        ['book', 'Jyotish Shastra ke Drishti se Netra Rog Vimarsh', null, ['title_hi' => 'ज्योतिष शास्त्र की दृष्टि से नेत्र रोग विमर्श']],
        ['book', 'Prakritik Aapda aur Jyotish Shastra', null, ['title_hi' => 'प्राकृतिक आपदा और ज्योतिष शास्त्र']],
        ['book', 'Cancer Rog ka Jyotishshastriya Adhyayan', null, ['title_hi' => 'कैंसर रोग का ज्योतिषशास्त्रीय अध्ययन']],
        ['book', 'Ved Manjari, Nitya Karma Ratna Mala & Sanskrit Praveshika', null, ['title_hi' => 'वेद मंजरी, नित्य कर्म रत्न माला एवं संस्कृत प्रवेशिका']],

        ['media', 'ETV Patna and Maurya TV', 'Daily programmes', [
            'detail' => 'Daily programmes: ETV (2010, four months) and Maurya TV (2011)',
            'title_hi' => 'ईटीवी पटना एवं मौर्य टीवी', 'detail_hi' => 'दैनिक कार्यक्रम: ईटीवी (2010, चार महीने) एवं मौर्य टीवी (2011)']],
        ['media', 'News18 and Kashish News', null, ['title' => 'News18, Kashish News and Aaj', 'title_hi' => 'न्यूज़18, कशिश न्यूज़ एवं आज']],
        ['media', 'Dainik Bhaskar', null, ['title_hi' => 'दैनिक भास्कर', 'detail_hi' => 'स्तम्भकार']],
        ['media', 'Dainik Jagran', null, ['title_hi' => 'दैनिक जागरण', 'detail_hi' => 'स्तम्भकार']],
        ['media', 'Hindustan', null, ['title_hi' => 'हिन्दुस्तान', 'detail_hi' => 'साप्ताहिक स्तम्भ']],
        ['media', 'Radio Mirchi 98.3 FM & Radio City', null, ['title_hi' => 'रेडियो मिर्ची 98.3 एफ़एम एवं रेडियो सिटी', 'detail_hi' => '"काल धमाल"']],
        ['media', 'Jyotishprabha Traimasik Patrika, Patna', null, ['title_hi' => 'ज्योतिषप्रभा त्रैमासिक पत्रिका, पटना', 'detail_hi' => 'सम्पादक']],
    ];

    /**
     * New entries: [section, title, detail, year, group, featured, photo number, title_hi, detail_hi].
     *
     * @var list<array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: bool, 6: ?int, 7: string, 8: ?string}>
     */
    private const NEW_ENTRIES = [
        ['award', 'Ayodhya Lifetime Achievement Award', 'Ayodhya Astrological Symposium (IVAF and The Twelve Gates Astrology), Praveg Tent City, Ayodhya, 23 Feb 2025', '2025', 'international', true, 9,
            'अयोध्या लाइफटाइम अचीवमेंट अवार्ड', 'अयोध्या एस्ट्रोलॉजिकल सिम्पोज़ियम (IVAF एवं द ट्वेल्व गेट्स एस्ट्रोलॉजी), प्रवेग टेंट सिटी, अयोध्या, 23 फ़रवरी 2025'],
        ['award', 'Aryabhatt Kewal Sach Samman 2025', 'Kewal Sach Times, 20th foundation year, Patna, 22 June 2025', '2025', 'national', false, 8,
            'आर्यभट्ट केवल सच सम्मान 2025', 'केवल सच टाइम्स, 20वाँ स्थापना वर्ष, पटना, 22 जून 2025'],
        ['award', 'Asia Pacific Vastu Award 2024', 'Banaras ’24 Astrological & Spiritual Meet (IVAF), Varanasi', '2024', 'international', false, 1,
            'एशिया पैसिफ़िक वास्तु अवार्ड 2024', 'बनारस ’24 एस्ट्रोलॉजिकल एंड स्पिरिचुअल मीट (IVAF), वाराणसी'],
        ['award', 'Memento of Appreciation — International Symposium', 'Department of Sanskrit, Marwari College (L.N.M.U.), Darbhanga, 20–21 Dec 2024', '2024', 'national', false, 6,
            'सम्मान-चिह्न — अन्तर्राष्ट्रीय संगोष्ठी', 'संस्कृत विभाग, मारवाड़ी महाविद्यालय (ल.ना.मि.वि.), दरभंगा, 20–21 दिसम्बर 2024'],
        ['award', 'Dinkar Janmotsav 2024 honour', 'Memento at the birth-anniversary celebration of Rashtrakavi Ramdhari Singh "Dinkar"', '2024', 'national', false, 3,
            'दिनकर जन्मोत्सव 2024 सम्मान', 'राष्ट्रकवि रामधारी सिंह "दिनकर" जन्मोत्सव में सप्रेम भेंट'],
        ['award', 'Shanti Devi Sanskrit Sadhak Samman', 'Bihar Sanskrit Sanjeevan Samaj, Pataliputra ("Sarvatra Sanskritam" campaign), Patna, 21 April 2023', '2023', 'national', false, 15,
            'शान्ति देवी संस्कृत साधक सम्मान', 'बिहार संस्कृत संजीवन समाज, पाटलिपुत्रम् ("सर्वत्र संस्कृतम्" अभियान), पटना, 21 अप्रैल 2023'],
        ['award', 'Honour at the 7th International Conference on Agricultural Growth', 'Krishi Evam Swasthya Amrit Mahotsav & Mega Agri Expo (International Year of Millets), Krishi Vigyan Kendra, Madhubani, 11–13 Feb 2023', '2023', 'national', false, 13,
            'सातवें अन्तर्राष्ट्रीय कृषि सम्मेलन में सम्मान', 'कृषि एवं स्वास्थ्य अमृत महोत्सव एवं मेगा एग्री एक्सपो (अन्तर्राष्ट्रीय मिलेट वर्ष), कृषि विज्ञान केन्द्र, मधुबनी, 11–13 फ़रवरी 2023'],
        ['award', 'Gita Jayanti Samaroh 2022 honour', 'Shrimad Bhagavad Gita Aapke Dwar Abhiyan, Bihar Hindi Sahitya Sammelan, Patna', '2022', 'national', false, 16,
            'गीता जयंती समारोह 2022 सम्मान', 'श्रीमद्भगवद्गीता आपके द्वार अभियान, बिहार हिन्दी साहित्य सम्मेलन, पटना'],
        ['award', 'Pandit Prabhunarayan Jha Samman', 'Satpath, Pandit Prabhunarayan Jha Samman Samaroh', '2018', 'national', false, 11,
            'पंडित प्रभुनारायण झा सम्मान', 'सत्पथ, पंडित प्रभुनारायण झा सम्मान समारोह'],
        ['award', 'Top position — Jyotish Shalaka Pariksha', 'K.S.D.S.U., Darbhanga', '1998', 'national', false, null,
            'ज्योतिष शलाका परीक्षा — सर्वोच्च स्थान', self::KSDSU_HI],
        ['award', 'Indo American Lifetime Achievement Award', 'Jagannath Puri (Odisha) International Conference, IVAF and Sohini Sastri Foundation', null, 'international', false, 4,
            'इंडो अमेरिकन लाइफटाइम अचीवमेंट अवार्ड', 'जगन्नाथ पुरी (ओडिशा) अन्तर्राष्ट्रीय सम्मेलन, IVAF एवं सोहिनी शास्त्री फ़ाउंडेशन'],
        ['award', 'International Gold Star Award', 'Vietnam International Astrological Conference (IVAF)', null, 'international', false, 2,
            'इंटरनेशनल गोल्ड स्टार अवार्ड', 'वियतनाम अन्तर्राष्ट्रीय ज्योतिष सम्मेलन (IVAF)'],
        ['award', 'Guest of Honour', 'International Vedic Astrology Federation LLC, USA (IVAF)', null, 'international', false, 5,
            'विशिष्ट अतिथि सम्मान', 'इंटरनेशनल वैदिक एस्ट्रोलॉजी फ़ेडरेशन LLC, USA (IVAF)'],

        ['research', 'Research papers at K.S.D.S.U.', 'Darbhanga (2014, 2015 and 2017)', '2014 – 2017', null, false, null,
            'शोध-पत्र, कामेश्वर सिंह दरभंगा संस्कृत विश्वविद्यालय', 'दरभंगा (2014, 2015 एवं 2017)'],
        ['research', 'Research paper, Oriental Conference', 'Tirupati', '2010', null, false, null, 'शोध-पत्र, ओरिएंटल कॉन्फ़्रेंस', 'तिरुपति'],
        ['research', 'Research papers, All India Astrological Conference', 'B.H.U., Varanasi (2008, 2009 and 2010)', '2008 – 2010', null, false, null,
            'शोध-पत्र, अखिल भारतीय ज्योतिष सम्मेलन', 'काशी हिन्दू विश्वविद्यालय, वाराणसी (2008, 2009 एवं 2010)'],
        ['research', 'Research paper, 2nd World Congress on Vedic Science', 'B.H.U., Varanasi', '2007', null, false, null,
            'शोध-पत्र, द्वितीय विश्व वैदिक विज्ञान सम्मेलन', 'काशी हिन्दू विश्वविद्यालय, वाराणसी'],
        ['research', 'Research paper, 27th International Astrological Conference', 'A.R.P., Kolkata', '2004', null, false, null,
            'शोध-पत्र, 27वाँ अन्तर्राष्ट्रीय ज्योतिष सम्मेलन', 'ए.आर.पी., कोलकाता'],

        ['media', 'Vishwasya Vrittantam (daily Sanskrit newspaper), Gujarat', 'Advisor', null, null, false, null,
            'विश्वस्य वृत्तान्तम् (दैनिक संस्कृत समाचार-पत्र), गुजरात', 'सलाहकार'],
    ];

    /** Trophy album photos in order (01–16): [caption, caption_hi]. */
    private const PHOTOS = [
        ['Asia Pacific Vastu Award 2024, Banaras ’24 Astrological & Spiritual Meet', 'एशिया पैसिफ़िक वास्तु अवार्ड 2024, बनारस ’24'],
        ['International Gold Star Award, Vietnam International Astrological Conference', 'इंटरनेशनल गोल्ड स्टार अवार्ड, वियतनाम अन्तर्राष्ट्रीय ज्योतिष सम्मेलन'],
        ['Dinkar Janmotsav 2024', 'दिनकर जन्मोत्सव 2024'],
        ['Indo American Lifetime Achievement Award, Jagannath Puri International Conference', 'इंडो अमेरिकन लाइफटाइम अचीवमेंट अवार्ड, जगन्नाथ पुरी अन्तर्राष्ट्रीय सम्मेलन'],
        ['Guest of Honour, International Vedic Astrology Federation (IVAF)', 'विशिष्ट अतिथि सम्मान, इंटरनेशनल वैदिक एस्ट्रोलॉजी फ़ेडरेशन (IVAF)'],
        ['Memento of Appreciation, International Symposium, Marwari College, Darbhanga (Dec 2024)', 'सम्मान-चिह्न, अन्तर्राष्ट्रीय संगोष्ठी, मारवाड़ी महाविद्यालय, दरभंगा (दिसम्बर 2024)'],
        ['Hind Gaurav Samman 2024, Indore', 'हिन्द गौरव सम्मान 2024, इंदौर'],
        ['Aryabhatt Kewal Sach Samman 2025, Patna', 'आर्यभट्ट केवल सच सम्मान 2025, पटना'],
        ['Ayodhya Lifetime Achievement Award 2025', 'अयोध्या लाइफटाइम अचीवमेंट अवार्ड 2025'],
        ['Aryabhatt Kewal Sach Samman 2025', 'आर्यभट्ट केवल सच सम्मान 2025'],
        ['Pandit Prabhunarayan Jha Samman Samaroh 2018', 'पंडित प्रभुनारायण झा सम्मान समारोह 2018'],
        ['Jyotish Shiromani Award 2024, Tamil Nadu Astrological Convocation, Chennai', 'ज्योतिष शिरोमणि सम्मान 2024, चेन्नई'],
        ['7th International Conference on Agricultural Growth, Krishi Vigyan Kendra, Madhubani (2023)', 'सातवाँ अन्तर्राष्ट्रीय कृषि सम्मेलन, कृषि विज्ञान केन्द्र, मधुबनी (2023)'],
        ['Jyotidhar Samrithi Award 2024, Tamil Nadu Astrological Convocation, Chennai', 'ज्योतिधर समृद्धि सम्मान 2024, चेन्नई'],
        ['Shanti Devi Sanskrit Sadhak Samman, Patna (2023)', 'शान्ति देवी संस्कृत साधक सम्मान, पटना (2023)'],
        ['Gita Jayanti Samaroh 2022, Bihar Hindi Sahitya Sammelan, Patna', 'गीता जयंती समारोह 2022, बिहार हिन्दी साहित्य सम्मेलन, पटना'],
    ];

    public function run(): void
    {
        $settings = app(SettingsRepository::class);
        if ($settings->get(self::FLAG) || ! DB::table('profile_entries')->exists()) {
            return;
        }
        DB::transaction(function (): void {
            foreach (self::CORRECTIONS as [$section, $title, $detail, $values]) {
                $this->correct($section, $title, $detail, $values);
            }
            foreach (self::NEW_ENTRIES as $entry) {
                $this->add(...$entry);
            }
            $this->album();
        });
        $settings->set(self::FLAG, now()->toDateString());
        Profile::forget();
    }

    public static function photo(int $number, bool $thumb = true): string
    {
        return sprintf('%s/%02d%s.jpg', self::DIR, $number, $thumb ? '-thumb' : '');
    }

    /** @param array<string, string|int|null> $values */
    private function correct(string $section, string $title, ?string $detail, array $values): void
    {
        $row = DB::table('profile_entries')->where('section', $section)->where('title', $title)->first();
        if (! $row) {
            return;
        }
        $update = [];
        $seededText = $detail === null || $row->detail === $detail;
        foreach ($values as $field => $value) {
            if (str_ends_with($field, '_hi')) {
                if (blank($row->{$field})) {
                    $update[$field] = $value;
                }
            } elseif ($field === 'image') {
                if (blank($row->image_path) && is_file(public_path(self::photo((int) $value)))) {
                    $update['image_path'] = self::photo((int) $value);
                }
            } elseif ($seededText) {
                $update[$field] = $value;
            }
        }
        if ($update) {
            DB::table('profile_entries')->where('id', $row->id)->update($update + ['updated_at' => now()]);
        }
    }

    private function add(string $section, string $title, ?string $detail, ?string $year, ?string $group, bool $featured, ?int $photo, string $titleHi, ?string $detailHi): void
    {
        if (DB::table('profile_entries')->where('section', $section)->where('title', $title)->exists()) {
            return;
        }
        DB::table('profile_entries')->insert([
            'section' => $section, 'title' => $title, 'title_hi' => $titleHi, 'detail' => $detail, 'detail_hi' => $detailHi, 'year' => $year, 'group' => $group,
            'image_path' => $photo && is_file(public_path(self::photo($photo))) ? self::photo($photo) : null,
            'featured' => $featured, 'published' => true, 'sort' => $this->sortFor($section, $year), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Places a new entry before the first entry of an earlier year (lists run newest first); undated entries go last. */
    private function sortFor(string $section, ?string $year): int
    {
        $rows = DB::table('profile_entries')->where('section', $section)->orderBy('sort')->orderBy('id')->get(['sort', 'year']);
        $start = $year !== null ? (int) $year : null;
        if ($start) {
            foreach ($rows as $r) {
                if ($r->year !== null && (int) $r->year > 0 && (int) $r->year < $start) {
                    return (int) $r->sort - 1;
                }
            }
        }

        return (int) $rows->max('sort') + 10;
    }

    private function album(): void
    {
        $slug = 'award-trophies-at-the-patna-office';
        if (DB::table('gallery_albums')->where('slug', $slug)->exists()) {
            return;
        }
        $id = DB::table('gallery_albums')->insertGetId([
            'ref' => (string) Str::ulid(), 'slug' => $slug, 'title' => 'Award trophies at the Patna office', 'title_hi' => 'पटना कार्यालय में रखे सम्मान',
            'description' => 'Trophies and mementos received by Acharya Ji in India and abroad, photographed at his office in Kankarbagh, Patna.',
            'description_hi' => 'देश-विदेश में आचार्य जी को मिले सम्मान और स्मृति-चिह्न, कंकड़बाग, पटना स्थित कार्यालय में।',
            'category' => 'awards', 'location' => 'Patna', 'published' => true, 'featured' => true,
            'sort' => (int) DB::table('gallery_albums')->min('sort') - 10, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (self::PHOTOS as $i => [$caption, $captionHi]) {
            $path = self::photo($i + 1, false);
            if (! is_file(public_path($path))) {
                continue;
            }
            [$w, $h] = getimagesize(public_path($path)) ?: [null, null];
            DB::table('gallery_items')->insert(['album_id' => $id, 'kind' => 'photo', 'path' => $path, 'thumb_path' => self::photo($i + 1), 'width' => $w, 'height' => $h,
                'caption' => $caption, 'caption_hi' => $captionHi, 'sort' => ($i + 1) * 10, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
