<?php

namespace App\Modules\Contracts\Database\Seeders;

use App\Modules\Contracts\Models\Unit;
use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use Illuminate\Database\Seeder;

/**
 * The units a contractor joins (الجهة الملتحق بها; D-098): the «الجهة» list of the owner's ملف التثبيت (sheet
 * «قائمة منسدلة»), without the entries that repeat another in another spelling or with a typo. The Contracts office
 * changes the list through the API (units). Safe in every environment and safe to run again.
 */
class UnitsSeeder extends Seeder
{
    use SeedsReferenceList;

    public const FROM_TEMPLATE = [
        'الفرقة 40', 'الفرقة 42', 'الفرقة 44', 'الفرقة 50', 'الفرقة 52', 'الفرقة 54', 'الفرقة 56', 'الفرقة 60',
        'الفرقة 64', 'الفرقة 66', 'الفرقة 70', 'الفرقة 72', 'الفرقة 74', 'الفرقة 76', 'الفرقة 82', 'الفرقة 84',
        'الفرقة 90', 'الفرقة 98', 'اللواء 812',
        'إدارة الأمن العسكري', 'مكتب نائب وزير الدفاع', 'إدارة الملاحق العسكرية', 'إدارة الانشاءات العسكرية',
        'إدارة المسيرات', 'إدارة المركبات', 'هيئة التدريب', 'وكالة الاستخبارات العسكرية',
        'القوى البحرية والدفاع الساحلي', 'هيئة التوجيه المعنوي', 'هيئة الاتصالات وتكنولوجيا المعلومات',
        'رئاسة الأركان', 'الإدارة العامة لوزارة الدفاع', 'إدارة المدفعية والصواريخ', 'إدارة التسليح', 'الحرس الجمهوري',
        'فرع المراسم العسكرية والحراسات', 'فرع نوادي الضباط', 'إدارة الخدمات الطبية', 'هيئة الإمداد والتموين',
        'إدارة شؤون الضباط', 'الإدارة المالية', 'إدارة الاستطلاع', 'القوى الجوية والدفاع الجوي',
        'إدارة الهندسة العسكرية', 'الشركة القابضة للصناعات العسكرية', 'مكتب وزير الدفاع', 'هيئة عمليات الأركان',
        'إدارة الإعلام والاتصال', 'مكتب تأمين الجيش', 'رابطة المحاربين القدماء', 'إدارة العقارات العسكرية',
        'شعبة التنظيم والإدارة', 'هيئة الرقابة والتفتيش', 'إدارة التجنيد العسكري', 'هيئة البحوث والصناعات العسكرية',
        'إدارة مختلف الصنوف', 'إدارة التجنيد والتعبئة',
    ];

    public function run(): void
    {
        $this->seedList(Unit::class, self::FROM_TEMPLATE);
    }
}
