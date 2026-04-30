import React from 'react';

export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = {
      hasError: false,
      error: null,
      errorInfo: null,
    };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true };
  }

  componentDidCatch(error, errorInfo) {
    console.error('Error caught by boundary:', error, errorInfo);
    this.setState({
      error,
      errorInfo,
    });
  }

  resetError = () => {
    this.setState({
      hasError: false,
      error: null,
      errorInfo: null,
    });
  };

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-gradient-to-br from-red-50 to-orange-50 flex items-center justify-center px-4">
          <div className="w-full max-w-md">
            <div className="bg-white rounded-lg shadow-lg p-8">
              <div className="text-center">
                <div className="inline-flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mb-4">
                  <svg
                    className="w-8 h-8 text-red-600"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M12 9v2m0 4v2m0 4v2m0-12a9 9 0 110-18 9 9 0 010 18z"
                    />
                  </svg>
                </div>

                <h1 className="text-2xl font-bold text-gray-900 mb-2">
                  Oops! Something Went Wrong
                </h1>
                <p className="text-gray-600 mb-4">
                  We encountered an unexpected error. Please try again or contact support.
                </p>

                {process.env.NODE_ENV === 'development' && this.state.error && (
                  <div className="mt-4 p-4 bg-gray-100 rounded-lg text-left max-h-48 overflow-auto">
                    <p className="font-mono text-xs text-gray-800">
                      <strong>Error:</strong> {this.state.error.toString()}
                    </p>
                    {this.state.errorInfo && (
                      <p className="font-mono text-xs text-gray-700 mt-2 whitespace-pre-wrap">
                        {this.state.errorInfo.componentStack}
                      </p>
                    )}
                  </div>
                )}

                <div className="flex gap-3 mt-6">
                  <button
                    onClick={this.resetError}
                    className="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition"
                  >
                    Try Again
                  </button>
                  <button
                    onClick={() => (window.location.href = '/')}
                    className="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-2 px-4 rounded-lg transition"
                  >
                    Go Home
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
