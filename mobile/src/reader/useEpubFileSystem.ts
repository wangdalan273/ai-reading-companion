import { useCallback, useState } from 'react';
import * as FileSystem from 'expo-file-system/legacy';

export function useEpubFileSystem() {
  const [file, setFile] = useState<string | null>(null);
  const [progress, setProgress] = useState(0);
  const [downloading, setDownloading] = useState(false);
  const [size, setSize] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const downloadFile = useCallback(async (fromUrl: string, toFile: string) => {
    setDownloading(true);
    try {
      const task = FileSystem.createDownloadResumable(
        fromUrl,
        `${FileSystem.documentDirectory}${toFile}`,
        { cache: true },
        ({ totalBytesWritten, totalBytesExpectedToWrite }) => setProgress(totalBytesExpectedToWrite > 0 ? Math.round(totalBytesWritten / totalBytesExpectedToWrite * 100) : 0),
      );
      const value = await task.downloadAsync();
      if (!value) throw new Error('Download failed');
      const length = value.headers['Content-Length'];
      if (length) setSize(Number(length));
      setSuccess(true); setError(null); setFile(value.uri);
      return { uri: value.uri, mimeType: value.mimeType };
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Error downloading file');
      return { uri: null, mimeType: null };
    } finally { setDownloading(false); }
  }, []);

  const getFileInfo = useCallback(async (fileUri: string) => {
    const info = await FileSystem.getInfoAsync(fileUri);
    return { uri: info.uri, exists: info.exists, isDirectory: info.isDirectory, size: info.exists ? info.size : undefined };
  }, []);

  return {
    file, progress, downloading, size, error, success, downloadFile, getFileInfo,
    documentDirectory: FileSystem.documentDirectory!, cacheDirectory: FileSystem.cacheDirectory!,
    bundleDirectory: FileSystem.bundleDirectory ?? undefined,
    readAsStringAsync: FileSystem.readAsStringAsync,
    writeAsStringAsync: FileSystem.writeAsStringAsync,
    deleteAsync: FileSystem.deleteAsync,
  };
}
