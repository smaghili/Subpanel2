<?php
function jdate($format, $timestamp = '', $none = '', $time_zone = 'Asia/Tehran', $tr_num = 'fa') {
    $T_sec = 0;

    if ($time_zone != 'local') date_default_timezone_set($time_zone);
    $ts = $T_sec + (($timestamp === '') ? time() : tr_num($timestamp));
    $date = explode('_', date('H_i_j_n_O_P_s_w_Y', $ts));
    list($j_y, $j_m, $j_d) = gregorian_to_jalali($date[8], $date[3], $date[2]);
    $doy = ($j_m < 7) ? (($j_m - 1) * 31) + $j_d - 1 : (($j_m - 7) * 30) + $j_d + 185;
    $kab = (((($j_y + 12) % 33) % 4) == 1) ? 1 : 0;
    $sl = strlen($format);
    $out = '';
    for ($i = 0; $i < $sl; $i++) {
        $sub = substr($format, $i, 1);
        if ($sub == '\\') {
            $out .= substr($format, ++$i, 1);
            continue;
        }
        switch ($sub) {
            case 'B':
            case 'e':
            case 'g':
            case 'G':
            case 'h':
            case 'I':
            case 'T':
            case 'u':
            case 'Z':
                $out .= date($sub, $ts);
                break;
            case 'a':
                $out .= ($date[0] < 12) ? 'ق.ظ' : 'ب.ظ';
                break;
            case 'A':
                $out .= ($date[0] < 12) ? 'قبل از ظهر' : 'بعد از ظهر';
                break;
            case 'd':
                $out .= ($j_d < 10) ? '0' . $j_d : $j_d;
                break;
            case 'D':
                $out .= jdate_words(array('kh' => $date[7]), ' ');
                break;
            case 'f':
                $out .= jdate_words(array('ff' => $j_m), ' ');
                break;
            case 'F':
                $out .= jdate_words(array('mm' => $j_m), ' ');
                break;
            case 'H':
                $out .= $date[0];
                break;
            case 'i':
                $out .= $date[1];
                break;
            case 'j':
                $out .= $j_d;
                break;
            case 'l':
                $out .= jdate_words(array('rh' => $date[7]), ' ');
                break;
            case 'm':
                $out .= ($j_m > 9) ? $j_m : '0' . $j_m;
                break;
            case 'M':
                $out .= jdate_words(array('km' => $j_m), ' ');
                break;
            case 'n':
                $out .= $j_m;
                break;
            case 's':
                $out .= $date[6];
                break;
            case 'S':
                $out .= 'ام';
                break;
            case 't':
                $out .= ($j_m != 12) ? (31 - (int)($j_m / 7) * 30) : ($kab + 29);
                break;
            case 'w':
                $out .= ($date[7] == 6) ? 0 : $date[7] + 1;
                break;
            case 'W':
                $avs = (($date[7] == 6) ? 0 : $date[7] + 1) - ($doy % 7);
                if ($avs < 0) $avs += 7;
                $num = (int)(($doy + $avs) / 7);
                if ($avs < 4) {
                    $num++;
                } elseif ($num < 1) {
                    $num = ($avs == 4 or $avs == ((((($j_y % 33) % 4) - 2) == ((int)(($j_y % 33) * 0.05))) ? 5 : 4)) ? 53 : 52;
                }
                $aks = $avs + $kab;
                if ($aks == 7) $aks = 0;
                $out .= (($kab + 363 - $doy) < $aks and $aks < 3) ? '01' : (($num < 10) ? '0' . $num : $num);
                break;
            case 'y':
                $out .= substr($j_y, 2, 2);
                break;
            case 'Y':
                $out .= $j_y;
                break;
            default:
                $out .= $sub;
        }
    }
    return ($tr_num != 'en') ? tr_num($out, 'fa', '.') : $out;
}

function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return array($jy, $jm, $jd);
}

function tr_num($str, $mod = 'en', $mf = '٫') {
    $num_a = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.');
    $key_a = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', $mf);
    return ($mod == 'fa') ? str_replace($num_a, $key_a, $str) : str_replace($key_a, $num_a, $str);
}

function jdate_words($array, $mod = '') {
    foreach ($array as $type => $num) {
        $num = (int)tr_num($num);
        switch ($type) {
            case 'ss':
                $sl = strlen($num);
                $xy3 = substr($num, 2 - $sl, 1);
                $h3 = $h34 = $h4 = '';
                if ($xy3 == 1) {
                    $p34 = '';
                    $k34 = array('ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده');
                    $h34 = $k34[substr($num, 2 - $sl, 2) - 10];
                } else {
                    $xy4 = substr($num, 3 - $sl, 1);
                    $p34 = ($xy3 == 0 or $xy4 == 0) ? '' : ' و ';
                    $k3 = array('', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود');
                    $h3 = $k3[$xy3];
                    $k4 = array('', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه');
                    $h4 = $k4[$xy4];
                }
                $array[$type] = (($num > 99) ? str_replace(array('12', '13', '14', '19', '20')
                        , array('هزار و دویست', 'هزار و سیصد', 'هزار و چهارصد', 'هزار و نهصد', 'دوهزار')
                        , substr($num, 0, 2)) . ((substr($num, 2, 2) == '00') ? '' : ' و ') : '') . $h3 . $p34 . $h34 . $h4;
                break;

            case 'mm':
                $key = array('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
                $array[$type] = $key[$num - 1];
                break;

            case 'rr':
                $key = array('یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده'
                , 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده', 'بیست', 'بیست و یک', 'بیست و دو', 'بیست و سه'
                , 'بیست و چهار', 'بیست و پنج', 'بیست و شش', 'بیست و هفت', 'بیست و هشت', 'بیست و نه', 'سی', 'سی و یک');
                $array[$type] = $key[$num - 1];
                break;

            case 'rh':
                $key = array('یکشنبه', 'دوشنبه', 'سه شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه');
                $array[$type] = $key[$num];
                break;

            case 'sh':
                $key = array('مار', 'اسب', 'گوسفند', 'میمون', 'مرغ', 'سگ', 'خوک', 'موش', 'گاو', 'پلنگ', 'خرگوش', 'نهنگ');
                $array[$type] = $key[$num % 12];
                break;

            case 'mb':
                $key = array('حمل', 'ثور', 'جوزا', 'سرطان', 'اسد', 'سنبله', 'میزان', 'عقرب', 'قوس', 'جدی', 'دلو', 'حوت');
                $array[$type] = $key[$num - 1];
                break;

            case 'ff':
                $key = array('بهار', 'تابستان', 'پاییز', 'زمستان');
                $array[$type] = $key[(int)($num / 3.1)];
                break;

            case 'km':
                $key = array('فر', 'ار', 'خر', 'تی‍', 'مر', 'شه‍', 'مه‍', 'آب‍', 'آذ', 'دی', 'به‍', 'اس‍');
                $array[$type] = $key[$num - 1];
                break;

            case 'kh':
                $key = array('ی', 'د', 'س', 'چ', 'پ', 'ج', 'ش');
                $array[$type] = $key[$num];
                break;

            default:
                $array[$type] = $num;
        }
    }
    return ($mod === '') ? $array : implode($mod, $array);
}
?> 