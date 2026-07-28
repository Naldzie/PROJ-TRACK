<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Welcome to PMU</title>
</head>

<body style="margin:0; padding:0; font-family:Arial, sans-serif; background:#f4f6f9;">

<table width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center">

<table width="520" cellpadding="0" cellspacing="0"
       style="background:#ffffff; margin:40px auto; border-radius:12px; overflow:hidden; box-shadow:0 8px 20px rgba(0,0,0,0.08);">

    <!-- HEADER WITH IMAGE + TEXT -->
    <tr>
        <td background="https://drive.google.com/uc?export=view&id=1H4iWVbIjMo4syTlfl8y9kXlCRtyQFCTm"
            style="background-size:cover; background-position:center; text-align:center; padding:70px 20px; color:#ffffff;">

            <h2 style="margin:0; font-size:26px; font-weight:bold; text-shadow:0 2px 6px rgba(0,0,0,0.4);">
                PROJ-TRACK SYSTEM
            </h2>

            <p style="margin:8px 0 0; font-size:14px; letter-spacing:0.5px; text-shadow:0 1px 4px rgba(0,0,0,0.4);">
                Contractor Account Access
            </p>

        </td>
    </tr>

    <!-- BODY -->
    <tr>
        <td style="padding:35px 30px; text-align:center;">

            <h3 style="margin-bottom:10px; color:#111; font-size:20px;">
                Welcome to PROJ-TRACK SYSTEM
            </h3>
            <p style="color:#555; font-size:15px; line-height:1.6;">
                You have been added as a <strong>Contractor</strong> in the PROJ-TRACK SYSTEM
            </p>

            <p style="margin:20px 0; color:#555; font-size:15px;">
                You can now access your account using your Google email:
            </p>

            <p style="font-weight:bold; color:#111; font-size:16px; margin-bottom:25px;">
                {{ $email }}
            </p>

            <a href="{{ url('/login') }}"
               style="display:inline-block;
                      padding:14px 32px;
                      background:#2563eb;
                      color:#ffffff;
                      text-decoration:none;
                      border-radius:8px;
                      font-weight:bold;
                      font-size:15px;
                      box-shadow:0 4px 12px rgba(37,99,235,0.3);">
                Login to PROJ-TRACK SYSTEM
            </a>

            <p style="margin-top:25px; font-size:13px; color:#888;">
                Please use your Google account to sign in.
            </p>

        </td>
    </tr>

    <!-- FOOTER -->
    <tr>
        <td style="background:#f9fafb; padding:18px; text-align:center; font-size:12px; color:#777;">
            © {{ date('Y') }} PMU System. All rights reserved.
        </td>
    </tr>

</table>

</td>
</tr>
</table>

</body>
</html>