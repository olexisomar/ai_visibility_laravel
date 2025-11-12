<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Account - AI Visibility Tracker</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .select-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 500px;
            padding: 40px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #374151;
            font-size: 24px;
            margin-bottom: 8px;
        }

        .header p {
            color: #6b7280;
            font-size: 14px;
        }

        .account-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .account-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .account-card:hover {
            border-color: #667eea;
            background: #f9fafb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }

        .account-card input[type="radio"] {
            width: 20px;
            height: 20px;
        }

        .account-info {
            flex: 1;
        }

        .account-name {
            font-size: 18px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
        }

        .account-domain {
            font-size: 14px;
            color: #6b7280;
        }

        .btn-continue {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 24px;
            transition: all 0.2s;
        }

        .btn-continue:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-continue:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
    </style>
</head>
<body>
    <div class="select-container">
        <div class="header">
            <h1>Select Account</h1>
            <p>Choose which account you want to access</p>
        </div>

        <form method="POST" action="{{ route('account.select') }}" id="accountForm">
            @csrf

            <div class="account-list">
                @foreach($accounts as $account)
                    <label class="account-card">
                        <input 
                            type="radio" 
                            name="account_id" 
                            value="{{ $account->id }}"
                            required
                        >
                        <div class="account-info">
                            <div class="account-name">{{ $account->name }}</div>
                            <div class="account-domain">{{ $account->domain }}</div>
                        </div>
                    </label>
                @endforeach
            </div>

            <button type="submit" class="btn-continue">
                Continue
            </button>
        </form>
    </div>

    <script>
        // Auto-submit when account is selected
       /* document.querySelectorAll('input[name="account_id"]').forEach(radio => {
            radio.addEventListener('change', () => {
                setTimeout(() => {
                    document.getElementById('accountForm').submit();
                }, 300);
            });
        });*/
    </script>
</body>
</html>