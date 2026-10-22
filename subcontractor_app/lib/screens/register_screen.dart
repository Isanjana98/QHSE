import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../services/subcontractor_api.dart';
import 'login_screen.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();

  final _vendorCodeController = TextEditingController();
  final _companyNameController = TextEditingController();
  final _companyAddressController = TextEditingController();
  final _businessRegNumController = TextEditingController();
  final _businessRegDateController = TextEditingController();
  final _epfController = TextEditingController();
  final _etfController = TextEditingController();
  final _ownerNameController = TextEditingController();
  final _ownerAddressController = TextEditingController();
  final _ownerIdController = TextEditingController();
  final _ownerDobController = TextEditingController();
  final _mobileController = TextEditingController();
  final _emailController = TextEditingController();
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();
  final _agreementEffectiveDateController = TextEditingController();
  final _agreementTerminationDateController = TextEditingController();
  final _machineriesController = TextEditingController();
  final _toolsController = TextEditingController();
  final _maleWorkersController = TextEditingController();
  final _femaleWorkersController = TextEditingController();

  String? _ownerGender;
  String? _selectedScope;
  bool _agreementAccepted = false;
  bool _isLoading = false;

  final List<String> _divisionsList = [
    "Solar",
    "MEP",
    "FIT",
    "HEL",
    "FSF",
    "UPS",
    "Transport",
    "EQT",
    "HR",
    "BT",
    "Supply Chain",
    "Compliance and ESG",
    "S & C",
    "NEX-GEN",
    "Marketing",
  ];
  final Map<String, bool> _selectedDivisions = {};

  final List<String> _scopesList = [
    "HVAC",
    "Fire protection Systems",
    "Plumbing service",
    "Electrical works",
    "Solar PV system services",
    "Networking",
    "Civil Engineering",
    "Steel works",
    "Scaffolding",
    "Other",
  ];

  List<TextEditingController> get _allControllers => [
    _vendorCodeController,
    _companyNameController,
    _companyAddressController,
    _businessRegNumController,
    _businessRegDateController,
    _epfController,
    _etfController,
    _ownerNameController,
    _ownerAddressController,
    _ownerIdController,
    _ownerDobController,
    _mobileController,
    _emailController,
    _usernameController,
    _passwordController,
    _confirmPasswordController,
    _agreementEffectiveDateController,
    _agreementTerminationDateController,
    _machineriesController,
    _toolsController,
    _maleWorkersController,
    _femaleWorkersController,
  ];

  @override
  void initState() {
    super.initState();
    for (var div in _divisionsList) {
      _selectedDivisions[div] = false;
    }
  }

  @override
  void dispose() {
    for (final c in _allControllers) {
      c.dispose();
    }
    super.dispose();
  }

  void _snack(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _submitRegistration() async {
    if (!_formKey.currentState!.validate()) return;

    final divisionSelected = _selectedDivisions.values.any((s) => s);
    if (!divisionSelected) {
      _snack('Please select at least one division.');
      return;
    }

    if (_passwordController.text != _confirmPasswordController.text) {
      _snack('Passwords do not match.');
      return;
    }

    if (!_agreementAccepted) {
      _snack('Please accept the agreement before continuing.');
      return;
    }

    setState(() => _isLoading = true);

    final chosenDivisions = _selectedDivisions.entries
        .where((entry) => entry.value)
        .map((entry) => entry.key)
        .toList();

    try {
      // Same server + folder as the login API.
      final url = Uri.parse('${SubcontractorApi.baseUrl}/register_api.php');

      final response = await http
          .post(
            url,
            headers: {
              "Content-Type": "application/json",
              "Accept": "application/json",
            },
            body: jsonEncode({
              "vendor_code": _vendorCodeController.text.trim(),
              "company_name": _companyNameController.text.trim(),
              "company_address": _companyAddressController.text.trim(),
              "business_reg_number": _businessRegNumController.text.trim(),
              "business_reg_date": _businessRegDateController.text.trim(),
              "epf": _epfController.text.trim(),
              "etf": _etfController.text.trim(),
              "owner_name": _ownerNameController.text.trim(),
              "owner_address": _ownerAddressController.text.trim(),
              "owner_id": _ownerIdController.text.trim(),
              "owner_gender": _ownerGender,
              "owner_dob": _ownerDobController.text.trim(),
              "mobile": _mobileController.text.trim(),
              "email": _emailController.text.trim(),
              "username": _usernameController.text.trim(),
              "password": _passwordController.text,
              "agreement_effective_date": _agreementEffectiveDateController.text
                  .trim(),
              "agreement_termination_date": _agreementTerminationDateController
                  .text
                  .trim(),
              "divisions": chosenDivisions,
              "scope": _selectedScope,
              "machineries": _machineriesController.text.trim(),
              "tools": _toolsController.text.trim(),
              "male_workers": _maleWorkersController.text.trim(),
              "female_workers": _femaleWorkersController.text.trim(),
            }),
          )
          .timeout(const Duration(seconds: 20));

      if (!mounted) return;
      setState(() => _isLoading = false);

      final data = jsonDecode(response.body);

      if (response.statusCode == 200 && data['success'] == true) {
        _snack(data['message']?.toString() ?? 'Registration successful!');

        Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => const SafetyTutorialQuizScreen(),
          ),
        );
      } else {
        _snack(data['message']?.toString() ?? 'Registration failed.');
      }
    } catch (e) {
      if (!mounted) return;
      setState(() => _isLoading = false);
      _snack('Connection error: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('External Service Provider Registration'),
        backgroundColor: const Color(0xFF147D72),
        foregroundColor: Colors.white,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 800),
            child: Card(
              elevation: 4,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
              child: Padding(
                padding: const EdgeInsets.all(24.0),
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'Hayleys Fentons - Subcontractor Registration',
                        style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.bold,
                          color: const Color(0xFF0F172A),
                        ),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        'Please enter your details below to join as a subcontractor.',
                        style: TextStyle(fontSize: 12, color: Colors.grey),
                        textAlign: TextAlign.center,
                      ),
                      const Divider(height: 32),
                      _buildTextField(
                        'Vendor Code *',
                        _vendorCodeController,
                        required: true,
                      ),
                      _buildTextField(
                        '1. Company Name *',
                        _companyNameController,
                        required: true,
                      ),
                      _buildTextField(
                        '2. Company Address *',
                        _companyAddressController,
                        required: true,
                      ),
                      _buildTextField(
                        '3. Business Reg Number *',
                        _businessRegNumController,
                        required: true,
                      ),
                      _buildTextField(
                        '4. Business Reg Date (YYYY-MM-DD) *',
                        _businessRegDateController,
                        required: true,
                        hint: 'YYYY-MM-DD',
                      ),
                      _buildTextField('5. EPF Number', _epfController),
                      _buildTextField('6. ETF Number', _etfController),
                      _buildTextField(
                        '7. Owner Name *',
                        _ownerNameController,
                        required: true,
                      ),
                      _buildTextField(
                        '8. Owner Address *',
                        _ownerAddressController,
                        required: true,
                      ),
                      _buildTextField(
                        '9. Owner ID *',
                        _ownerIdController,
                        required: true,
                      ),
                      const Text(
                        '10. Gender of the Company Owner *',
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                        ),
                      ),
                      const SizedBox(height: 4),
                      DropdownButtonFormField<String>(
                        value: _ownerGender,
                        decoration: _inputDecoration('Select gender'),
                        items: const [
                          DropdownMenuItem(value: 'Male', child: Text('Male')),
                          DropdownMenuItem(
                            value: 'Female',
                            child: Text('Female'),
                          ),
                        ],
                        onChanged: (val) => setState(() => _ownerGender = val),
                        validator: (val) =>
                            val == null ? 'Please select gender' : null,
                      ),
                      const SizedBox(height: 14),
                      _buildTextField(
                        '11. Owner DOB (YYYY-MM-DD) *',
                        _ownerDobController,
                        required: true,
                        hint: 'YYYY-MM-DD',
                      ),
                      _buildTextField(
                        '12. Mobile Number *',
                        _mobileController,
                        required: true,
                        keyboardType: TextInputType.phone,
                      ),
                      _buildTextField(
                        '13. E-mail address *',
                        _emailController,
                        required: true,
                        keyboardType: TextInputType.emailAddress,
                      ),
                      _buildTextField(
                        'Username *',
                        _usernameController,
                        required: true,
                      ),
                      _buildTextField(
                        'Password *',
                        _passwordController,
                        required: true,
                        obscureText: true,
                      ),
                      _buildTextField(
                        'Confirm Password *',
                        _confirmPasswordController,
                        required: true,
                        obscureText: true,
                      ),
                      _buildTextField(
                        '14. Agreement Effective Date *',
                        _agreementEffectiveDateController,
                        required: true,
                        hint: 'YYYY-MM-DD',
                      ),
                      _buildTextField(
                        '15. Agreement Termination Date *',
                        _agreementTerminationDateController,
                        required: true,
                        hint: 'YYYY-MM-DD',
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        '16. Division hired by *',
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 8,
                        children: _divisionsList.map((div) {
                          return SizedBox(
                            width: 220,
                            child: CheckboxListTile(
                              title: Text(
                                div,
                                style: const TextStyle(fontSize: 12),
                              ),
                              value: _selectedDivisions[div],
                              controlAffinity: ListTileControlAffinity.leading,
                              contentPadding: EdgeInsets.zero,
                              dense: true,
                              onChanged: (bool? val) {
                                setState(() {
                                  _selectedDivisions[div] = val ?? false;
                                });
                              },
                            ),
                          );
                        }).toList(),
                      ),
                      const SizedBox(height: 14),
                      const Text(
                        '17. Scope *',
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                        ),
                      ),
                      const SizedBox(height: 4),
                      DropdownButtonFormField<String>(
                        value: _selectedScope,
                        isExpanded: true,
                        decoration: _inputDecoration('Select scope'),
                        items: _scopesList.map((scope) {
                          return DropdownMenuItem(
                            value: scope,
                            child: Text(
                              scope,
                              style: const TextStyle(fontSize: 12),
                            ),
                          );
                        }).toList(),
                        onChanged: (val) =>
                            setState(() => _selectedScope = val),
                        validator: (val) =>
                            val == null ? 'Please select a scope' : null,
                      ),
                      const SizedBox(height: 14),
                      _buildTextField(
                        '18. Machineries available',
                        _machineriesController,
                        maxLines: 2,
                      ),
                      _buildTextField(
                        '19. Tools available',
                        _toolsController,
                        maxLines: 2,
                      ),
                      _buildTextField(
                        '20. Number of male workers *',
                        _maleWorkersController,
                        required: true,
                        keyboardType: TextInputType.number,
                      ),
                      _buildTextField(
                        '21. Number of female workers *',
                        _femaleWorkersController,
                        required: true,
                        keyboardType: TextInputType.number,
                      ),
                      CheckboxListTile(
                        title: const Text(
                          '22. I have read and understand the guidelines and agree. *',
                          style: TextStyle(fontSize: 12),
                        ),
                        value: _agreementAccepted,
                        controlAffinity: ListTileControlAffinity.leading,
                        contentPadding: EdgeInsets.zero,
                        onChanged: (val) =>
                            setState(() => _agreementAccepted = val ?? false),
                      ),
                      const SizedBox(height: 20),
                      ElevatedButton.icon(
                        onPressed: _isLoading ? null : _submitRegistration,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF098336),
                          foregroundColor: Colors.white,
                          minimumSize: const Size.fromHeight(45),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(6),
                          ),
                        ),
                        icon: _isLoading
                            ? const SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(
                                  color: Colors.white,
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.arrow_forward_rounded),
                        label: Text(
                          _isLoading ? 'Submitting...' : 'Next (ගෙනයන්න)',
                          style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildTextField(
    String label,
    TextEditingController controller, {
    bool required = false,
    int maxLines = 1,
    TextInputType? keyboardType,
    String? hint,
    bool obscureText = false,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14.0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(
              fontWeight: FontWeight.w600,
              fontSize: 12,
              color: Color(0xFF334155),
            ),
          ),
          const SizedBox(height: 4),
          TextFormField(
            controller: controller,
            maxLines: maxLines,
            keyboardType: keyboardType,
            obscureText: obscureText,
            decoration: _inputDecoration(hint),
            validator: required
                ? (val) => val == null || val.trim().isEmpty
                      ? 'This field is required'
                      : null
                : null,
          ),
        ],
      ),
    );
  }

  InputDecoration _inputDecoration(String? hint) {
    return InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(fontSize: 12, color: Colors.grey),
      filled: true,
      fillColor: const Color(0xFFF8FAFC),
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(6),
        borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(6),
        borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(6),
        borderSide: const BorderSide(color: Color(0xFF16A34A), width: 1.5),
      ),
    );
  }
}

/// After registration the user has no API token yet, so we send them
/// back to the login screen to sign in with their new username/password.
class SafetyTutorialQuizScreen extends StatelessWidget {
  const SafetyTutorialQuizScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Safety Tutorial & Quiz'),
        backgroundColor: const Color(0xFF147D72),
        foregroundColor: Colors.white,
      ),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 600),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(
                  Icons.video_library_rounded,
                  size: 80,
                  color: Color(0xFF147D72),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Safety Training Video & Quiz',
                  style: TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF0F172A),
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 32),
                ElevatedButton.icon(
                  onPressed: () {
                    Navigator.of(context).pushAndRemoveUntil(
                      MaterialPageRoute<void>(
                        builder: (_) => const LoginScreen(),
                      ),
                      (_) => false,
                    );
                  },
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF147D72),
                    foregroundColor: Colors.white,
                    minimumSize: const Size.fromHeight(45),
                  ),
                  icon: const Icon(Icons.arrow_forward),
                  label: const Text('Complete Quiz & Go to Sign In'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
