import yfinance as yf
import pandas as pd

def get_kd_indicator(symbol, n=9):
    # 1. 抓取歷史股價 (近半年)
    df = yf.download(symbol, period="6mo")
    
    # yfinance 某些版本會回傳多層欄位，先轉成單層欄位避免運算錯誤
    if isinstance(df.columns, pd.MultiIndex):
        df.columns = df.columns.get_level_values(0)
    
    # 2. 計算 RSV (未成熟隨機值)
    # RSV = (今日收盤 - N日內最低) / (N日內最高 - N日內最低) * 100
    df['low_n'] = df['Low'].rolling(window=n).min()
    df['high_n'] = df['High'].rolling(window=n).max()
    df['RSV'] = (df['Close'] - df['low_n']) / (df['high_n'] - df['low_n']) * 100
    
    # 3. 計算 K 與 D
    # 初始值通常設為 50
    df['K'] = 50.0
    df['D'] = 50.0
    
    # 使用迴圈依照公式平滑計算：K = 2/3*前K + 1/3*RSV ; D = 2/3*前D + 1/3*新K
    for i in range(n, len(df)):
        df.loc[df.index[i], 'K'] = df.loc[df.index[i-1], 'K'] * (2/3) + df.loc[df.index[i], 'RSV'] * (1/3)
        df.loc[df.index[i], 'D'] = df.loc[df.index[i-1], 'D'] * (2/3) + df.loc[df.index[i], 'K'] * (1/3)
        
    return df[['Close', 'K', 'D']].tail()

# 執行範例
print("台積電 (2330) 最新 KD 指標：")
print(get_kd_indicator("2891.TW"))
