<?php
$divisions = [
    'Solar', 'MEP', 'FIT', 'HEL', 'FSF', 'UPS', 'Transport', 'EQT', 'HR', 'BT',
    'Supply Chain', 'Compliance and ESG', 'S & C', 'NEX-GEN', 'Marketing',
];

$scopes = [
    'HVAC', 'Fire protection Systems (ගිනි ආරක්ෂණ පද්ධති)', 'Plumbing service (ජලනල සේවා)',
    'Electrical works (විදුලිය ආශ්‍රිත කාර්යයන්)', 'Solar PV system services (සූර්ය PV පද්ධති සේවා)',
    'Networking (ජාලකරණය)', 'Civil Engineering (සිවිල් ඉංජිනේරු)', 'Steel works (වානේ සම්බන්ධ කාර්යයන්)',
    'Scaffolding (පළංචි)', 'Life Line (ජීවිත ආරක්ෂණ කේබල්)', 'Transportation (ප්‍රවාහන)',
    'Stores Management (ගබඩා කළමනාකරණය)', 'Tea Making (තේ සැදීම)', 'ELV',
    'Security Services (ආරක්ෂක සේවා)', 'Camera Systems (කැමරා පද්ධති)', 'Cleaning (පිරිසිදු කිරීම)',
    'Kitchen Services (මුළුතැන්ගේ සේවා)', 'Pest Control (පළිබෝධ පාලනය)',
    'Glass Cleaning (වීදුරු පිරිසිදු කිරීම)', 'Telecommunication (සන්නිවේදන)', 'Other',
];

function subcontractorMissingProfileFields(array $record, array $fields): array
{
    $missing = [];
    foreach ($fields as $key => $field) {
        $value = $record[$key] ?? null;
        if ($key === 'agreement_accept' ? empty($value) : ($value === null || trim((string)$value) === '')) {
            $missing[$key] = $field;
        }
    }
    return $missing;
}

return [
    'vendor_code' => ['label' => 'Vendor Code', 'type' => 'text'],
    'company_name' => ['label' => 'Company Name', 'type' => 'text'],
    'company_address' => ['label' => 'Company Address', 'type' => 'text'],
    'business_reg_number' => ['label' => 'Business Registration Number', 'type' => 'text'],
    'business_reg_date' => ['label' => 'Business Registration Issued Date', 'type' => 'date'],
    'epf_number' => ['label' => 'EPF Registration Number', 'type' => 'text'],
    'etf_number' => ['label' => 'ETF Registration Number', 'type' => 'text'],
    'owner_name' => ['label' => 'Company Owner Name', 'type' => 'text'],
    'owner_address' => ['label' => 'Company Owner Address', 'type' => 'text'],
    'owner_id_number' => ['label' => 'Owner ID Number', 'type' => 'text'],
    'owner_gender' => ['label' => 'Owner Gender', 'type' => 'select', 'options' => ['Male', 'Female', 'Non-binary', 'Prefer not to say']],
    'owner_dob' => ['label' => 'Owner Date of Birth', 'type' => 'date'],
    'mobile_number' => ['label' => 'Mobile Number', 'type' => 'tel'],
    'email' => ['label' => 'Email Address', 'type' => 'email'],
    'username' => ['label' => 'Username', 'type' => 'text'],
    'agreement_effective_date' => ['label' => 'Agreement Effective Date', 'type' => 'date'],
    'agreement_termination_date' => ['label' => 'Agreement Termination Date', 'type' => 'date'],
    'division' => ['label' => 'Division Hired By', 'type' => 'checkboxes', 'options' => $divisions],
    'scope' => ['label' => 'Scope of Work', 'type' => 'select', 'options' => $scopes],
    'machineries' => ['label' => 'Machineries Available', 'type' => 'textarea'],
    'tools' => ['label' => 'Tools Available', 'type' => 'textarea'],
    'male_workers' => ['label' => 'Number of Male Workers', 'type' => 'number'],
    'female_workers' => ['label' => 'Number of Female Workers', 'type' => 'number'],
    'agreement_accept' => ['label' => 'Accept the Agreement and Guidelines', 'type' => 'checkbox'],
    'password' => ['label' => 'Account Password', 'type' => 'password'],
];